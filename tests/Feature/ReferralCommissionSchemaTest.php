<?php

namespace Tests\Feature;

use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ReferralCommissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_exactly_three_active_default_commission_levels(): void
    {
        $levels = ReferralCommissionLevel::query()->orderBy('level')->get();

        $this->assertSame([1, 2, 3], $levels->pluck('level')->all());
        $this->assertSame(['1500.00', '1000.00', '500.00'], $levels->pluck('amount')->all());
        $this->assertTrue($levels->every(fn (ReferralCommissionLevel $level): bool => $level->is_active));
    }

    public function test_database_rejects_a_fourth_commission_level(): void
    {
        $this->expectException(QueryException::class);

        ReferralCommissionLevel::query()->create([
            'level' => 4,
            'amount' => '250.00',
            'is_active' => true,
        ]);
    }

    public function test_qualification_and_commission_constraints_and_model_relationships_work(): void
    {
        $customer = User::factory()->create();
        $beneficiary = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);

        $qualification = ReferralQualification::query()->create([
            'qualified_user_id' => $customer->id,
            'qualifying_withdrawal_id' => $withdrawal->id,
            'cumulative_usdt_equivalent' => '500.00000000',
            'threshold_usdt' => '500.00000000',
            'qualified_at' => now(),
        ]);
        $level = ReferralCommissionLevel::query()->where('level', 1)->firstOrFail();
        $commission = ReferralCommission::query()->create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $customer->id,
            'level' => 1,
            'amount' => '1500.00',
            'configuration_snapshot' => ['level' => 1, 'amount' => '1500.00'],
            'triggering_withdrawal_id' => $withdrawal->id,
            'status' => 'pending',
            'qualified_at' => now(),
        ]);

        $this->assertSame($qualification->id, $customer->referralQualification->id);
        $this->assertSame($withdrawal->id, $qualification->qualifyingWithdrawal->id);
        $this->assertSame($beneficiary->id, $commission->beneficiary->id);
        $this->assertSame($customer->id, $commission->qualifiedUser->id);
        $this->assertSame($commission->id, $qualification->commissions->sole()->id);
        $this->assertSame('1500.00', $commission->amount);
        $this->assertSame('500.00000000', $qualification->cumulative_usdt_equivalent);
        $this->assertSame('115.25000000', $withdrawal->fresh()->usdt_inr_rate_snapshot);
    }

    public function test_qualification_unique_constraints_prevent_reprocessing_a_customer(): void
    {
        $customer = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);
        $this->qualificationFor($customer, $withdrawal);

        $this->expectException(QueryException::class);
        $this->qualificationFor($customer, $withdrawal);
    }

    public function test_commission_unique_constraints_prevent_duplicate_level_rewards(): void
    {
        $customer = User::factory()->create();
        $beneficiary = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);
        $qualification = $this->qualificationFor($customer, $withdrawal);

        $this->commissionFor($qualification, $customer, $beneficiary, $withdrawal);

        $this->expectException(QueryException::class);
        $this->commissionFor($qualification, $customer, $beneficiary, $withdrawal);
    }

    public function test_commission_and_level_records_cannot_be_deleted_through_models(): void
    {
        $this->expectException(LogicException::class);
        ReferralCommissionLevel::query()->where('level', 1)->firstOrFail()->delete();
    }

    public function test_commission_snapshot_is_unchanged_when_configuration_changes(): void
    {
        $customer = User::factory()->create();
        $beneficiary = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);
        $qualification = $this->qualificationFor($customer, $withdrawal);
        $commission = $this->commissionFor($qualification, $customer, $beneficiary, $withdrawal);

        $level = $commission->commissionLevel;
        $level->update(['amount' => '2000.00']);

        $this->assertSame('2000.00', $level->fresh()->amount);
        $this->assertSame('1500.00', $commission->fresh()->amount);
        $this->assertSame('1500.00', $commission->fresh()->configuration_snapshot['amount']);

        $this->expectException(LogicException::class);
        $commission->delete();
    }

    public function test_withdrawal_rate_snapshot_is_nullable_for_existing_history(): void
    {
        $withdrawal = $this->withdrawalFor(User::factory()->create(), null);

        $this->assertNull($withdrawal->usdt_inr_rate_snapshot);
        $this->assertDatabaseHas('withdrawal_requests', [
            'id' => $withdrawal->id,
            'usdt_inr_rate_snapshot' => null,
        ]);
    }

    public function test_withdrawal_rate_snapshot_cannot_be_rewritten_once_recorded(): void
    {
        $withdrawal = $this->withdrawalFor(User::factory()->create());

        $this->expectException(LogicException::class);
        $withdrawal->update(['usdt_inr_rate_snapshot' => '120.00000000']);
    }

    public function test_recorded_commission_ledger_link_cannot_be_changed(): void
    {
        $customer = User::factory()->create();
        $beneficiary = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);
        $qualification = $this->qualificationFor($customer, $withdrawal);
        $commission = $this->commissionFor($qualification, $customer, $beneficiary, $withdrawal);
        $ledgerEntry = InrLedgerEntry::query()->create([
            'user_id' => $beneficiary->id,
            'source_type' => 'referral_commission',
            'source_id' => $commission->id,
            'entry_type' => 'credit',
            'amount' => '1500.00',
        ]);
        $commission->update(['ledger_entry_id' => $ledgerEntry->id]);

        $this->expectException(LogicException::class);
        $commission->update(['ledger_entry_id' => null]);
    }

    public function test_referral_migration_refuses_to_drop_existing_financial_history(): void
    {
        $customer = User::factory()->create();
        $beneficiary = User::factory()->create();
        $withdrawal = $this->withdrawalFor($customer);
        $qualification = $this->qualificationFor($customer, $withdrawal);
        $this->commissionFor($qualification, $customer, $beneficiary, $withdrawal);
        $migration = require database_path('migrations/2026_10_09_110000_create_referral_commission_tables.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_withdrawal_rate_snapshot_migration_refuses_to_drop_saved_rates(): void
    {
        $this->withdrawalFor(User::factory()->create());
        $migration = require database_path('migrations/2026_10_09_100000_add_usdt_inr_rate_snapshot_to_withdrawal_requests.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function qualificationFor(User $customer, WithdrawalRequest $withdrawal): ReferralQualification
    {
        return ReferralQualification::query()->create([
            'qualified_user_id' => $customer->id,
            'qualifying_withdrawal_id' => $withdrawal->id,
            'cumulative_usdt_equivalent' => '500.00000000',
            'threshold_usdt' => '500.00000000',
            'qualified_at' => now(),
        ]);
    }

    private function commissionFor(
        ReferralQualification $qualification,
        User $customer,
        User $beneficiary,
        WithdrawalRequest $withdrawal
    ): ReferralCommission {
        $level = ReferralCommissionLevel::query()->where('level', 1)->firstOrFail();

        return ReferralCommission::query()->create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $customer->id,
            'level' => 1,
            'amount' => '1500.00',
            'configuration_snapshot' => ['level' => 1, 'amount' => '1500.00'],
            'triggering_withdrawal_id' => $withdrawal->id,
            'status' => 'pending',
            'qualified_at' => now(),
        ]);
    }

    private function withdrawalFor(User $user, ?string $rateSnapshot = '115.25000000'): WithdrawalRequest
    {
        return WithdrawalRequest::query()->create([
            'request_reference' => 'WDR-'.Str::upper(Str::random(12)),
            'user_id' => $user->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => '57500.00',
            'usdt_inr_rate_snapshot' => $rateSnapshot,
            'bank_account_holder' => 'Test Customer',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '123456789',
            'bank_ifsc_code' => 'TEST0000123',
            'status' => 'completed',
            'requested_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
