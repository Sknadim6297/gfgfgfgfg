<?php

namespace Tests\Feature;

use App\Models\ExchangeRequest;
use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReferralManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_management_routes_require_admin_authentication_and_two_factor(): void
    {
        $this->get(route('admin.referrals.settings'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.referrals.commissions'))->assertRedirect(route('admin.login'));

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.referrals.settings'))
            ->assertOk();
        $this->get(route('admin.referrals.commissions'))
            ->assertOk();

        $customer = User::factory()->create();
        $this->actingAs($customer, 'admin')
            ->get(route('admin.referrals.settings'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_settings_show_exactly_three_levels_and_admin_can_update_amount_with_audit_history(): void
    {
        $admin = $this->admin();
        $this->adminSession($admin)->get(route('admin.referrals.settings'))
            ->assertOk()
            ->assertSee('Level 1')
            ->assertSee('Level 2')
            ->assertSee('Level 3')
            ->assertDontSee('Level 4')
            ->assertSee('Referral Management')
            ->assertSee('1500.00')
            ->assertSee('1000.00')
            ->assertSee('500.00');

        $level = ReferralCommissionLevel::query()->where('level', 1)->firstOrFail();
        $this->put(route('admin.referrals.levels.update', $level), ['amount_1' => '1750.25'])
            ->assertRedirect();

        $this->assertSame('1750.25', $level->fresh()->amount);
        $this->assertSame($admin->id, $level->fresh()->updated_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'admin.referral_commission_level_updated',
            'auditable_type' => ReferralCommissionLevel::class,
            'auditable_id' => $level->id,
        ]);
        $this->get(route('admin.referrals.settings'))
            ->assertOk()
            ->assertSee('Referral commission amount updated. Previously recorded commissions are unchanged.')
            ->assertSee('₹1,750.25');
    }

    public function test_amount_validation_rejects_zero_negative_excess_precision_and_level_four(): void
    {
        $admin = $this->admin();
        $level = ReferralCommissionLevel::query()->where('level', 1)->firstOrFail();
        foreach (['0', '-1.00', '12.345', '9999999999999999999.00', 'not-money'] as $invalidAmount) {
            $this->adminSession($admin)
                ->from(route('admin.referrals.settings'))
                ->put(route('admin.referrals.levels.update', $level), ['amount_1' => $invalidAmount])
                ->assertRedirect(route('admin.referrals.settings'))
                ->assertSessionHasErrors('amount_1');
        }

        $this->assertSame('1500.00', $level->fresh()->amount);
        $this->put('/admin/referrals/levels/4', ['amount_4' => '100.00'])->assertNotFound();
    }

    public function test_deactivation_and_restoration_are_audited_and_preserve_historical_commissions_and_ledger(): void
    {
        $admin = $this->admin();
        [$commission, $level] = $this->commissionFixture();
        $snapshot = $commission->configuration_snapshot;
        $ledgerId = $commission->ledger_entry_id;
        $this->adminSession($admin)->put(route('admin.referrals.levels.update', $level), ['amount_1' => '1800.00'])
            ->assertRedirect();
        $this->assertSame('1800.00', $level->fresh()->amount);
        $this->assertSame('1500.00', $commission->fresh()->amount);
        $this->assertSame($snapshot, $commission->fresh()->configuration_snapshot);

        $this->patch(route('admin.referrals.levels.status', $level), ['is_active' => '0'])
            ->assertRedirect();

        $this->assertFalse($level->fresh()->is_active);
        $this->assertDatabaseHas('referral_commissions', ['id' => $commission->id, 'amount' => '1500.00', 'ledger_entry_id' => $ledgerId]);
        $this->assertDatabaseHas('inr_ledger_entries', ['id' => $ledgerId, 'amount' => '1500.00']);
        $this->assertSame($snapshot, $commission->fresh()->configuration_snapshot);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.referral_commission_level_deactivated']);

        $this->patch(route('admin.referrals.levels.status', $level), ['is_active' => '1'])->assertRedirect();
        $this->assertTrue($level->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.referral_commission_level_restored']);
        $this->assertSame(1, ReferralCommission::query()->count());
        $this->assertSame(1, InrLedgerEntry::query()->where('id', $ledgerId)->count());
    }

    public function test_report_search_filters_status_level_dates_and_displays_ledger_link_and_summary(): void
    {
        [$first] = $this->commissionFixture('Beneficiary Alpha', 'Qualified Alpha', 1, 'credited', now()->subDays(3));
        $this->commissionFixture('Beneficiary Beta', 'Qualified Beta', 2, 'reversed', now()->subDay());
        $admin = $this->admin();

        $response = $this->adminSession($admin)->get(route('admin.referrals.commissions', [
            'search' => 'Qualified Alpha',
            'level' => '1',
            'status' => 'credited',
            'date_from' => now()->subDays(4)->toDateString(),
            'date_to' => now()->subDays(2)->toDateString(),
        ]));
        $response->assertOk()
            ->assertSee('Beneficiary Alpha')
            ->assertSee('Qualified Alpha')
            ->assertSee('Level 1')
            ->assertSee('#'.$first->ledger_entry_id)
            ->assertDontSee('Beneficiary Beta')
            ->assertSee('₹1,500.00');
    }

    public function test_referral_amount_summary_uses_all_time_inr_status_totals_even_when_report_is_filtered(): void
    {
        $this->commissionFixture('Credited Beneficiary', 'Credited Customer', 1, 'credited');
        $this->commissionFixture('Pending Beneficiary', 'Pending Customer', 3, 'pending');
        $this->commissionFixture('Reversed Beneficiary', 'Reversed Customer', 2, 'reversed');

        $this->adminSession($this->admin())
            ->get(route('admin.referrals.commissions', ['status' => 'credited']))
            ->assertOk()
            ->assertSee('All-time global commission amounts; report filters only affect the table below.')
            ->assertSee('₹3,000.00')
            ->assertSee('₹500.00')
            ->assertSee('₹1,500.00')
            ->assertSee('₹1,000.00')
            ->assertDontSee('Reversed Beneficiary');
    }

    public function test_admin_referral_report_searches_sell_transaction_references(): void
    {
        $level = ReferralCommissionLevel::query()->where('level', 1)->firstOrFail();
        $beneficiary = User::factory()->create(['name' => 'Sell Referral Beneficiary']);
        $qualified = User::factory()->create(['name' => 'Sell Qualified Customer']);
        $sell = ExchangeRequest::create([
            'user_id' => $qualified->id,
            'request_reference' => 'QPMSELLREFERRALSEARCH',
            'rate_plan_name' => 'Saved rate',
            'usdt_amount' => '500.00000000',
            'exchange_rate' => '100.00000000',
            'inr_amount' => '50000.00',
            'available_usd_before' => '500.00000000',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $qualification = ReferralQualification::create([
            'qualified_user_id' => $qualified->id,
            'qualifying_exchange_request_id' => $sell->id,
            'cumulative_usdt_equivalent' => '500.00000000',
            'threshold_usdt' => '500.00000000',
            'qualified_at' => now(),
        ]);
        $commission = ReferralCommission::create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $qualified->id,
            'level' => 1,
            'amount' => $level->amount,
            'configuration_snapshot' => ['level' => 1, 'amount' => (string) $level->amount],
            'triggering_exchange_request_id' => $sell->id,
            'status' => 'credited',
            'qualified_at' => now(),
            'credited_at' => now(),
        ]);
        $ledger = InrLedgerEntry::create([
            'user_id' => $beneficiary->id,
            'source_type' => 'referral_commission',
            'source_id' => $commission->id,
            'entry_type' => 'credit',
            'amount' => $level->amount,
        ]);
        $commission->forceFill(['ledger_entry_id' => $ledger->id])->save();

        $this->adminSession($this->admin())
            ->get(route('admin.referrals.commissions', ['search' => $sell->request_reference]))
            ->assertOk()
            ->assertSee('Sell Referral Beneficiary')
            ->assertSee('Sell Qualified Customer')
            ->assertSee('Source transaction')
            ->assertSee($sell->request_reference);
    }

    public function test_report_validation_and_pagination_are_server_side(): void
    {
        for ($index = 0; $index < 27; $index++) {
            $this->commissionFixture('Beneficiary '.$index, 'Qualified '.$index, 1, 'credited', now()->subMinutes($index));
        }

        $admin = $this->admin();
        $this->adminSession($admin)->get(route('admin.referrals.commissions', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->subDays(1)->toDateString(),
        ]))->assertSessionHasErrors('date_to');

        $this->get(route('admin.referrals.commissions'))
            ->assertOk()
            ->assertSee('Showing')
            ->assertSee('Next')
            ->assertSee('Qualified 0')
            ->assertDontSee('Qualified 26');
        $this->get(route('admin.referrals.commissions', ['page' => 2]))
            ->assertOk()
            ->assertSee('Qualified 26');
    }

    private function commissionFixture(
        string $beneficiaryName = 'Referral Beneficiary',
        string $qualifiedName = 'Qualified Customer',
        int $levelNumber = 1,
        string $status = 'credited',
        ?Carbon $qualifiedAt = null,
    ): array {
        $level = ReferralCommissionLevel::query()->where('level', $levelNumber)->firstOrFail();
        $beneficiary = User::factory()->create([
            'name' => $beneficiaryName,
            'inr_balance' => '1500.00',
        ]);
        $qualifiedUser = User::factory()->create(['name' => $qualifiedName]);
        $withdrawal = WithdrawalRequest::query()->create([
            'request_reference' => 'WDR-'.Str::upper(Str::random(12)),
            'user_id' => $qualifiedUser->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => '50000.00',
            'usdt_inr_rate_snapshot' => '100.00000000',
            'payout_method' => 'cash',
            'status' => 'completed',
            'requested_at' => now(),
            'completed_at' => $qualifiedAt ?? now(),
        ]);
        $qualification = ReferralQualification::query()->create([
            'qualified_user_id' => $qualifiedUser->id,
            'qualifying_withdrawal_id' => $withdrawal->id,
            'cumulative_usdt_equivalent' => '500.00000000',
            'threshold_usdt' => '500.00000000',
            'qualified_at' => $qualifiedAt ?? now(),
        ]);
        $commission = ReferralCommission::query()->create([
            'referral_qualification_id' => $qualification->id,
            'referral_commission_level_id' => $level->id,
            'beneficiary_user_id' => $beneficiary->id,
            'qualified_user_id' => $qualifiedUser->id,
            'level' => $levelNumber,
            'amount' => $level->amount,
            'configuration_snapshot' => ['level' => $levelNumber, 'amount' => (string) $level->amount],
            'triggering_withdrawal_id' => $withdrawal->id,
            'status' => $status,
            'qualified_at' => $qualifiedAt ?? now(),
            'credited_at' => $status === 'credited' ? ($qualifiedAt ?? now()) : null,
        ]);
        $ledger = InrLedgerEntry::query()->create([
            'user_id' => $beneficiary->id,
            'source_type' => 'referral_commission',
            'source_id' => $commission->id,
            'entry_type' => 'credit',
            'amount' => $level->amount,
        ]);
        $commission->forceFill(['ledger_entry_id' => $ledger->id])->save();

        return [$commission->fresh(), $level];
    }

    private function adminSession(User $admin): static
    {
        return $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
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
