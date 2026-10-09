<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReferralCommissionService
{
    public const QUALIFICATION_THRESHOLD_USDT = '500.00000000';

    public function activeBaseRate(): ?string
    {
        $rate = ExchangeRate::query()
            ->where('plan_key', 'base')
            ->where('pair', 'USDT_INR')
            ->where('is_active', true)
            ->lockForUpdate()
            ->value('rate');

        if ($rate === null || Decimal::compare((string) $rate, '0.00000000', 8) <= 0) {
            return null;
        }

        return (string) $rate;
    }

    public function processCompletedWithdrawal(WithdrawalRequest $withdrawal, ?int $actorUserId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Referral qualification must run inside the withdrawal transaction.');
        }

        if ($withdrawal->status !== 'completed'
            || $withdrawal->completed_at === null
            || $withdrawal->usdt_inr_rate_snapshot === null
            || Decimal::compare((string) $withdrawal->usdt_inr_rate_snapshot, '0.00000000', 8) <= 0) {
            return;
        }

        $qualifiedUser = User::query()
            ->whereKey($withdrawal->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (ReferralQualification::query()
            ->where('qualified_user_id', $qualifiedUser->id)
            ->lockForUpdate()
            ->first() !== null) {
            return;
        }

        $withdrawals = WithdrawalRequest::query()
            ->where('user_id', $qualifiedUser->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotNull('usdt_inr_rate_snapshot')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'amount', 'usdt_inr_rate_snapshot', 'completed_at']);

        $cumulative = '0.00000000';
        $triggeringWithdrawalId = null;
        $qualifiedAt = null;
        foreach ($withdrawals as $completedWithdrawal) {
            if (Decimal::compare((string) $completedWithdrawal->usdt_inr_rate_snapshot, '0.00000000', 8) <= 0) {
                continue;
            }

            $usdtEquivalent = Decimal::divideToScale(
                (string) $completedWithdrawal->amount,
                (string) $completedWithdrawal->usdt_inr_rate_snapshot,
                dividendScale: 2,
                divisorScale: 8,
                resultScale: 8,
            );
            $cumulative = Decimal::add($cumulative, $usdtEquivalent, 8);

            if ($triggeringWithdrawalId === null
                && Decimal::compare($cumulative, self::QUALIFICATION_THRESHOLD_USDT, 8) >= 0) {
                $triggeringWithdrawalId = $completedWithdrawal->id;
                $qualifiedAt = $completedWithdrawal->completed_at;
                break;
            }
        }

        if ($triggeringWithdrawalId === null || $qualifiedAt === null) {
            return;
        }

        $qualification = ReferralQualification::query()->create([
            'qualified_user_id' => $qualifiedUser->id,
            'qualifying_withdrawal_id' => $triggeringWithdrawalId,
            'cumulative_usdt_equivalent' => $cumulative,
            'threshold_usdt' => self::QUALIFICATION_THRESHOLD_USDT,
            'qualified_at' => $qualifiedAt,
        ]);

        $activeLevels = ReferralCommissionLevel::query()
            ->where('is_active', true)
            ->whereIn('level', [1, 2, 3])
            ->orderBy('level')
            ->lockForUpdate()
            ->get()
            ->keyBy('level');

        $this->creditEligibleUplines(
            $qualifiedUser,
            $qualification,
            $activeLevels,
            (int) $triggeringWithdrawalId,
            $actorUserId,
            $qualifiedAt,
        );
    }

    /**
     * @param  Collection<int, ReferralCommissionLevel>  $activeLevels
     */
    private function creditEligibleUplines(
        User $qualifiedUser,
        ReferralQualification $qualification,
        Collection $activeLevels,
        int $triggeringWithdrawalId,
        ?int $actorUserId,
        CarbonInterface $qualifiedAt,
    ): void {
        $visited = [(int) $qualifiedUser->id => true];
        $uplineId = $qualifiedUser->referred_by_user_id;

        for ($levelNumber = 1; $levelNumber <= 3 && $uplineId !== null; $levelNumber++) {
            $uplineId = (int) $uplineId;
            if (isset($visited[$uplineId])) {
                break;
            }
            $visited[$uplineId] = true;

            $upline = User::query()->whereKey($uplineId)->lockForUpdate()->first();
            if ($upline === null) {
                break;
            }

            $level = $activeLevels->get($levelNumber);
            if ($level !== null && $upline->role === 'user' && $upline->account_status === 'active') {
                $this->creditCommission(
                    $qualification,
                    $upline,
                    $qualifiedUser,
                    $level,
                    $triggeringWithdrawalId,
                    $actorUserId,
                    $qualifiedAt,
                );
            }

            $uplineId = $upline->referred_by_user_id;
        }
    }

    private function creditCommission(
        ReferralQualification $qualification,
        User $beneficiary,
        User $qualifiedUser,
        ReferralCommissionLevel $level,
        int $triggeringWithdrawalId,
        ?int $actorUserId,
        CarbonInterface $qualifiedAt,
    ): void {
        $amount = (string) $level->amount;
        if (Decimal::compare($amount, '0.00', 2) <= 0) {
            return;
        }

        $commission = ReferralCommission::query()->create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $qualifiedUser->id,
            'level' => $level->level,
            'amount' => $amount,
            'configuration_snapshot' => [
                'level' => $level->level,
                'amount' => $amount,
                'active' => $level->is_active,
                'updated_at' => $level->updated_at?->toISOString(),
            ],
            'triggering_withdrawal_id' => $triggeringWithdrawalId,
            'status' => 'pending',
            'qualified_at' => $qualifiedAt,
        ]);

        $beneficiary->forceFill([
            'inr_balance' => Decimal::add((string) $beneficiary->inr_balance, $amount, 2),
        ])->save();

        $ledgerEntry = InrLedgerEntry::query()->create([
            'user_id' => $beneficiary->id,
            'actor_user_id' => $actorUserId,
            'source_type' => 'referral_commission',
            'source_id' => $commission->id,
            'entry_type' => 'credit',
            'amount' => $amount,
        ]);

        $commission->forceFill([
            'ledger_entry_id' => $ledgerEntry->id,
            'status' => 'credited',
            'credited_at' => now(),
        ])->save();
    }
}
