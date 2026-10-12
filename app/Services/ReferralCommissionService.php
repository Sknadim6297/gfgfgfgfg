<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\ExchangeRequest;
use App\Models\User;
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

    public function processCompletedSell(ExchangeRequest $exchange): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Referral qualification must run inside the sell conversion transaction.');
        }

        if ($exchange->status !== 'completed'
            || $exchange->completed_at === null
            || $exchange->referral_eligible_at === null
            || $exchange->available_usd_before === null
            || Decimal::compare((string) $exchange->usdt_amount, '0.00000000', 8) <= 0) {
            return;
        }

        $qualifiedUser = User::query()
            ->whereKey($exchange->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (ReferralQualification::query()
            ->where('qualified_user_id', $qualifiedUser->id)
            ->lockForUpdate()
            ->first() !== null) {
            return;
        }

        $sales = ExchangeRequest::query()
            ->completedSell()
            ->where('user_id', $qualifiedUser->id)
            ->whereNotNull('referral_eligible_at')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'usdt_amount', 'completed_at']);

        $cumulative = '0.00000000';
        $triggeringExchangeId = null;
        $qualifiedAt = null;
        foreach ($sales as $completedSell) {
            $cumulative = Decimal::add($cumulative, (string) $completedSell->usdt_amount, 8);
            if (Decimal::compare($cumulative, self::QUALIFICATION_THRESHOLD_USDT, 8) >= 0) {
                $triggeringExchangeId = (int) $completedSell->id;
                $qualifiedAt = $completedSell->completed_at;
                break;
            }
        }

        if ($triggeringExchangeId === null || $qualifiedAt === null) {
            return;
        }

        $this->qualifyAndCredit(
            $qualifiedUser,
            $cumulative,
            $qualifiedAt,
            $triggeringExchangeId,
        );
    }

    private function qualifyAndCredit(
        User $qualifiedUser,
        string $cumulative,
        CarbonInterface $qualifiedAt,
        int $triggeringExchangeId,
    ): void {
        $qualification = ReferralQualification::query()->create([
            'qualified_user_id' => $qualifiedUser->id,
            'qualifying_exchange_request_id' => $triggeringExchangeId,
            'cumulative_usdt_equivalent' => $cumulative,
            'threshold_usdt' => self::QUALIFICATION_THRESHOLD_USDT,
            'qualified_at' => $qualifiedAt,
        ]);

        $levels = ReferralCommissionLevel::query()
            ->orderBy('level')
            ->lockForUpdate()
            ->get()
            ->keyBy('level');

        $this->creditEligibleUplines(
            $qualifiedUser,
            $qualification,
            $levels,
            $triggeringExchangeId,
            $qualifiedAt,
        );
    }

    /**
     * @param  Collection<int, ReferralCommissionLevel>  $levels
     */
    private function creditEligibleUplines(
        User $qualifiedUser,
        ReferralQualification $qualification,
        Collection $levels,
        int $triggeringExchangeId,
        CarbonInterface $qualifiedAt,
    ): void {
        $visited = [(int) $qualifiedUser->id => true];
        $uplineId = $qualifiedUser->referred_by_user_id;

        $maxLevel = (int) $levels->keys()->max();
        for ($levelNumber = 1; $levelNumber <= $maxLevel && $uplineId !== null; $levelNumber++) {
            $uplineId = (int) $uplineId;
            if (isset($visited[$uplineId])) {
                break;
            }
            $visited[$uplineId] = true;

            $upline = User::query()->whereKey($uplineId)->lockForUpdate()->first();
            if ($upline === null) {
                break;
            }

            $level = $levels->get($levelNumber);
            if ($level?->is_active && $upline->role === 'user' && $upline->account_status === 'active') {
                $this->creditCommission(
                    $qualification,
                    $upline,
                    $qualifiedUser,
                    $level,
                    $triggeringExchangeId,
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
        int $triggeringExchangeId,
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
            'triggering_exchange_request_id' => $triggeringExchangeId,
            'status' => 'pending',
            'qualified_at' => $qualifiedAt,
        ]);

        $beneficiary->forceFill([
            'inr_balance' => Decimal::add((string) $beneficiary->inr_balance, $amount, 2),
        ])->save();

        $ledgerEntry = InrLedgerEntry::query()->create([
            'user_id' => $beneficiary->id,
            'actor_user_id' => null,
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
