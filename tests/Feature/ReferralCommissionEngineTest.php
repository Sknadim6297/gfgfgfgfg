<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralCommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_requires_a_valid_active_base_rate_without_changing_financial_state(): void
    {
        $this->baseRate('100.00000000', false);
        $customer = User::factory()->create();
        $withdrawal = $this->withdrawal($customer, '50000.00');
        $customer->forceFill(['inr_balance' => '1000.00'])->save();
        $this->hold($customer, $withdrawal);
        $admin = $this->admin();

        $this->processing($withdrawal, $admin);
        $this->complete($withdrawal, $admin)
            ->assertUnprocessable()
            ->assertSee('An active Base Rate is required');

        $this->assertSame('processing', $withdrawal->fresh()->status);
        $this->assertNull($withdrawal->fresh()->usdt_inr_rate_snapshot);
        $this->assertSame('1000.00', $customer->fresh()->inr_balance);
        $this->assertSame(0, $withdrawal->inrLedgerEntries()->where('entry_type', 'capture')->count());

        $this->baseRate('0.00000000', true);
        $this->complete($withdrawal, $admin)->assertUnprocessable();
        $this->assertSame('processing', $withdrawal->fresh()->status);
    }

    public function test_completed_withdrawal_below_threshold_does_not_qualify(): void
    {
        $this->baseRate();
        $customer = User::factory()->create();
        $withdrawal = $this->withdrawal($customer, '49999.00');
        $admin = $this->admin();

        $this->completeFromPending($withdrawal, $admin)->assertRedirect();

        $this->assertSame('100.00000000', $withdrawal->fresh()->usdt_inr_rate_snapshot);
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_pending_rejected_and_historical_without_rate_withdrawals_do_not_count(): void
    {
        $this->baseRate();
        $customer = User::factory()->create();
        foreach ([
            ['amount' => '100000.00', 'status' => 'pending', 'rate' => null],
            ['amount' => '100000.00', 'status' => 'rejected', 'rate' => null],
            ['amount' => '100000.00', 'status' => 'completed', 'rate' => null],
        ] as $attributes) {
            $withdrawal = $this->withdrawal($customer, $attributes['amount']);
            DB::table('withdrawal_requests')->where('id', $withdrawal->id)->update([
                'status' => $attributes['status'],
                'completed_at' => $attributes['status'] === 'completed' ? now() : null,
                'usdt_inr_rate_snapshot' => $attributes['rate'],
            ]);
        }

        $admin = $this->admin();
        $current = $this->withdrawal($customer, '49999.00');
        $this->completeFromPending($current, $admin)->assertRedirect();

        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_completed_withdrawals_keep_rate_snapshots_but_do_not_qualify_referrals(): void
    {
        $this->baseRate('100.00000000');
        [$levelOne, $levelTwo, $levelThree, $customer] = $this->referralChain();
        $admin = $this->admin();

        $first = $this->withdrawal($customer, '20000.00');
        $this->completeFromPending($first, $admin)->assertRedirect();
        $this->assertSame(0, ReferralQualification::query()->count());

        ExchangeRate::query()->where('plan_key', 'base')->update(['rate' => '200.00000000']);
        $second = $this->withdrawal($customer, '60000.00');
        $this->completeFromPending($second, $admin)->assertRedirect();

        $this->assertSame('100.00000000', $first->fresh()->usdt_inr_rate_snapshot);
        $this->assertSame('200.00000000', $second->fresh()->usdt_inr_rate_snapshot);
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
        $this->assertSame('0.00', $levelTwo->fresh()->inr_balance);
        $this->assertSame('0.00', $levelThree->fresh()->inr_balance);
    }

    public function test_multiple_completed_withdrawals_alone_never_qualify_referrals(): void
    {
        $this->baseRate();
        [, , , $customer] = $this->referralChain();
        $admin = $this->admin();

        foreach (['20000.00', '15000.00', '15000.00'] as $amount) {
            $withdrawal = $this->withdrawal($customer, $amount);
            $this->completeFromPending($withdrawal, $admin)->assertRedirect();
        }

        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_withdrawal_completion_does_not_credit_any_referral_level(): void
    {
        $this->baseRate();
        [$levelOne, $levelTwo, $levelThree, $customer] = $this->referralChain();
        ReferralCommissionLevel::query()->where('level', 2)->update(['is_active' => false]);
        $admin = $this->admin();
        $withdrawal = $this->withdrawal($customer, '50000.00');

        $this->completeFromPending($withdrawal, $admin)->assertRedirect();

        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
        $this->assertSame('0.00', $levelTwo->fresh()->inr_balance);
        $this->assertSame('0.00', $levelThree->fresh()->inr_balance);
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_configured_commission_amounts_are_not_triggered_by_withdrawals(): void
    {
        $this->baseRate();
        [$levelOne, , , $customer] = $this->referralChain();
        ReferralCommissionLevel::query()->where('level', 1)->update(['amount' => '1777.77']);
        $withdrawal = $this->withdrawal($customer, '50000.00');

        $this->completeFromPending($withdrawal, $this->admin())->assertRedirect();

        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
    }

    public function test_completed_withdrawal_without_uplines_does_not_create_sell_qualification(): void
    {
        $this->baseRate();
        $customer = User::factory()->create();
        $admin = $this->admin();
        $withdrawal = $this->withdrawal($customer, '50000.00');

        $this->completeFromPending($withdrawal, $admin)->assertRedirect();

        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_duplicate_withdrawal_completion_does_not_duplicate_or_create_referral_credits(): void
    {
        $this->baseRate();
        [$levelOne, , , $customer] = $this->referralChain();
        $admin = $this->admin();
        $withdrawal = $this->withdrawal($customer, '50000.00');

        $this->completeFromPending($withdrawal, $admin)->assertRedirect();
        $this->complete($withdrawal, $admin)->assertUnprocessable();

        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
    }

    public function test_withdrawal_completion_does_not_create_referral_credits(): void
    {
        $this->baseRate();
        [$levelOne, , , $customer] = $this->referralChain();
        $admin = $this->admin();
        $withdrawal = $this->withdrawal($customer, '50000.00');
        $this->completeFromPending($withdrawal, $admin)->assertRedirect();

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertNotNull($withdrawal->fresh()->usdt_inr_rate_snapshot);
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
    }

    public function test_withdrawal_based_cycle_does_not_generate_referral_commissions(): void
    {
        $this->baseRate();
        [$levelOne, $levelTwo, $levelThree, $customer] = $this->referralChain();
        DB::table('users')->where('id', $levelOne->id)->update(['referred_by_user_id' => $customer->id]);
        $withdrawal = $this->withdrawal($customer, '50000.00');

        $this->completeFromPending($withdrawal, $this->admin())->assertRedirect();

        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertSame('0.00', $levelOne->fresh()->inr_balance);
        $this->assertSame('0.00', $levelTwo->fresh()->inr_balance);
        $this->assertSame('0.00', $levelThree->fresh()->inr_balance);
    }

    public function test_inr_to_usdt_conversion_uses_exact_decimal_rounding(): void
    {
        $this->assertSame(
            '0.33333333',
            Decimal::divideToScale('1.00', '3.00000000', dividendScale: 2, divisorScale: 8, resultScale: 8)
        );
        $this->assertSame(
            '500.00000000',
            Decimal::divideToScale('57500.00', '115.00000000', dividendScale: 2, divisorScale: 8, resultScale: 8)
        );
    }

    private function baseRate(string $rate = '100.00000000', bool $active = true): ExchangeRate
    {
        return ExchangeRate::query()->updateOrCreate(
            ['plan_key' => 'base'],
            [
                'pair' => 'USDT_INR',
                'name' => 'Base Rate',
                'rate' => $rate,
                'minimum_amount' => '0.00',
                'is_active' => $active,
                'is_default' => true,
            ]
        );
    }

    /**
     * @return array{User, User, User, User}
     */
    private function referralChain(): array
    {
        $levelThree = User::factory()->create();
        $levelTwo = User::factory()->create(['referred_by_user_id' => $levelThree->id]);
        $levelOne = User::factory()->create(['referred_by_user_id' => $levelTwo->id]);
        $customer = User::factory()->create(['referred_by_user_id' => $levelOne->id]);

        return [$levelOne, $levelTwo, $levelThree, $customer];
    }

    private function withdrawal(User $customer, string $amount): WithdrawalRequest
    {
        $customer->forceFill(['inr_balance' => '1000000.00'])->save();

        return WithdrawalRequest::query()->create([
            'request_reference' => 'WDR-'.Str::upper(Str::random(12)),
            'user_id' => $customer->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'payout_method' => 'bank',
            'bank_account_holder' => 'Test Customer',
            'bank_name' => 'Test Bank',
            'bank_account_number' => '123456789',
            'bank_ifsc_code' => 'TEST0000123',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    private function hold(User $customer, WithdrawalRequest $withdrawal): void
    {
        InrLedgerEntry::query()->create([
            'user_id' => $customer->id,
            'source_type' => 'withdrawal_request',
            'source_id' => $withdrawal->id,
            'entry_type' => 'hold',
            'amount' => $withdrawal->amount,
        ]);
    }

    private function completeFromPending(WithdrawalRequest $withdrawal, User $admin)
    {
        $this->processing($withdrawal, $admin);

        return $this->complete($withdrawal, $admin);
    }

    private function processing(WithdrawalRequest $withdrawal, User $admin): void
    {
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.withdrawals.update', $withdrawal), [
                'status' => 'processing',
                'transaction_reference' => '',
                'admin_notes' => '',
            ])->assertRedirect();
    }

    private function complete(WithdrawalRequest $withdrawal, User $admin)
    {
        return $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.withdrawals.update', $withdrawal), [
                'status' => 'completed',
                'transaction_reference' => 'PAYOUT-'.Str::upper(Str::random(10)),
                'admin_notes' => '',
            ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
        ]);
    }

}
