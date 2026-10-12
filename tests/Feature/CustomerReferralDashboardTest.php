<?php

namespace Tests\Feature;

use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReferralDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_configured_levels_and_only_credited_earnings_privately(): void
    {
        $owner = User::factory()->create(['inr_balance' => '3000.00']);
        $direct = User::factory()->create([
            'name' => 'Private Referred Name',
            'email' => 'private-referred@example.test',
            'referred_by_user_id' => $owner->id,
        ]);
        $secondLevel = User::factory()->create(['referred_by_user_id' => $direct->id]);
        $thirdLevel = User::factory()->create(['referred_by_user_id' => $secondLevel->id]);

        $this->completedWithdrawal($direct, '25000.00', '50.00000000');
        $this->completedWithdrawal($direct, '10000.00', '100.00000000', 'pending', null);
        $this->completedWithdrawal($direct, '50000.00', '100.00000000', 'rejected', null);
        $this->completedWithdrawal($direct, '50000.00', '0.00000000');
        $this->completedWithdrawal($direct, '50000.00', null);
        $this->createCommission($owner, $direct, 1, '1500.00');
        $this->createCommission($owner, $secondLevel, 2, '1000.00');
        $this->createCommission($owner, $thirdLevel, 3, '500.00');
        ReferralCommissionLevel::query()->where('level', 1)->update(['amount' => '1750.00']);
        ReferralCommissionLevel::query()->where('level', 3)->update(['is_active' => false]);

        $this->actingAs($owner, 'web')
            ->get(route('profile.referrals'))
            ->assertOk()
            ->assertSee('Referral dashboard')
            ->assertSee('Track your invitations, qualification progress, and credited rewards.')
            ->assertSee($owner->referral_code)
            ->assertSee(route('register', ['ref' => $owner->referral_code]))
            ->assertSee('Total referrals')
            ->assertSee('Qualified')
            ->assertSee('Pending qualification')
            ->assertSee('Total commission earned')
            ->assertSee('Qualification progress')
            ->assertSee('Commission history')
            ->assertSee('Member '.$direct->customer_id)
            ->assertSee('₹3,000.00')
            ->assertSee('₹1,750.00')
            ->assertSee('₹1,000.00')
            ->assertSee('₹500.00')
            ->assertSee('INR ledger #')
            ->assertSee('Reward credited')
            ->assertSee('Inactive')
            ->assertSee('Level 1')
            ->assertSee('Level 2')
            ->assertSee('Level 3')
            ->assertSee('500.000000 USDT')
            ->assertDontSee('Total Direct Referrals')
            ->assertDontSee('Private Referred Name')
            ->assertDontSee('private-referred@example.test');
    }

    public function test_referral_history_paginates_progress_and_labels_unqualified_rewards_as_estimates(): void
    {
        $owner = User::factory()->create();
        $referrals = collect();
        for ($index = 0; $index < 12; $index++) {
            $referrals->push(User::factory()->create([
                'name' => 'Private Referral '.$index,
                'email' => 'private'.$index.'@example.test',
                'referred_by_user_id' => $owner->id,
            ]));
        }

        $response = $this->actingAs($owner, 'web')
            ->get(route('profile.referrals.history'))
            ->assertOk()
            ->assertSee('Referral dashboard')
            ->assertSee('Qualification progress')
            ->assertSee('Estimated reward — not earned or payable until qualification.')
            ->assertSee($referrals[11]->customer_id)
            ->assertDontSee($referrals[11]->email)
            ->assertDontSee($referrals[11]->name);

        $this->get(route('profile.referrals.history', ['referral_page' => 2]))
            ->assertOk()
            ->assertSee($referrals[1]->customer_id);
    }

    public function test_referral_and_wallet_history_are_scoped_to_the_authenticated_customer(): void
    {
        $owner = User::factory()->create(['inr_balance' => '1500.00']);
        $other = User::factory()->create(['inr_balance' => '9999.00']);
        $qualified = User::factory()->create(['referred_by_user_id' => $owner->id]);
        $otherQualified = User::factory()->create(['referred_by_user_id' => $other->id]);
        $ownerCommission = $this->createCommission($owner, $qualified, 1, '1500.00');
        $otherCommission = $this->createCommission($other, $otherQualified, 1, '1500.00');

        $this->actingAs($owner, 'web')
            ->get(route('wallet'))
            ->assertOk()
            ->assertSee('Referral Commission History')
            ->assertSee('Level 1')
            ->assertSee('₹1,500.00')
            ->assertSee('Ledger #'.$ownerCommission->ledger_entry_id)
            ->assertDontSee('Ledger #'.$otherCommission->ledger_entry_id);

        $this->get(route('profile.referrals.history'))
            ->assertOk()
            ->assertSee($qualified->customer_id)
            ->assertDontSee($otherQualified->customer_id);
    }

    public function test_referral_pages_require_a_customer_account(): void
    {
        $this->get(route('profile.referrals'))->assertRedirect(route('login'));
        $this->get(route('profile.referrals.history'))->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')
            ->get(route('profile.referrals'))
            ->assertForbidden();
        $this->get(route('profile.referrals.history'))->assertForbidden();
    }

    private function completedWithdrawal(
        User $customer,
        string $amount,
        ?string $rate,
        string $status = 'completed',
        ?string $completedAt = null,
    ): WithdrawalRequest {
        return WithdrawalRequest::create([
            'request_reference' => 'WDR-'.Str::upper(Str::random(12)),
            'user_id' => $customer->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'usdt_inr_rate_snapshot' => $rate,
            'payout_method' => 'bank',
            'bank_account_holder' => 'Private Customer',
            'bank_name' => 'Private Bank',
            'bank_account_number' => '1234567890',
            'bank_ifsc_code' => 'EXMP0001234',
            'status' => $status,
            'requested_at' => now(),
            'completed_at' => $completedAt === null && $status === 'completed' ? now() : $completedAt,
        ]);
    }

    private function createCommission(User $beneficiary, User $qualified, int $levelNumber, string $amount): ReferralCommission
    {
        $withdrawal = $this->completedWithdrawal($qualified, '25000.00', '50.00000000');
        $qualification = ReferralQualification::create([
            'qualified_user_id' => $qualified->id,
            'qualifying_withdrawal_id' => $withdrawal->id,
            'cumulative_usdt_equivalent' => '500.00000000',
            'threshold_usdt' => '500.00000000',
            'qualified_at' => now(),
        ]);
        $level = ReferralCommissionLevel::query()->where('level', $levelNumber)->firstOrFail();
        $commission = ReferralCommission::create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $qualified->id,
            'level' => $levelNumber,
            'amount' => $amount,
            'configuration_snapshot' => ['level' => $levelNumber, 'amount' => $amount, 'active' => true],
            'triggering_withdrawal_id' => $withdrawal->id,
            'status' => 'pending',
            'qualified_at' => now(),
        ]);
        $ledgerEntry = InrLedgerEntry::create([
            'user_id' => $beneficiary->id,
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

        return $commission->fresh();
    }
}
