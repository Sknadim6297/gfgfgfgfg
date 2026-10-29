<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\DemoUsdtLedgerEntry;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoDepositApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_approval_credits_only_the_separate_demo_wallet_once(): void
    {
        config(['deposits.demo_approval_enabled' => true]);
        $customer = User::factory()->create(['balance' => '12.00000000']);
        $deposit = $this->deposit($customer, '200.00', true, 'ddfdfd');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit approved successfully.');

        $approved = $deposit->fresh();
        $this->assertSame('approved', $approved->status);
        $this->assertSame('ddfdfd', $approved->transaction_hash);
        $this->assertNull($approved->blockchain_verified_at);
        $this->assertSame($admin->id, $approved->reviewed_by_user_id);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertSame('12.00000000', $customer->fresh()->balance);
        $this->assertSame('200.00000000', $customer->fresh()->demo_usdt_balance);
        $this->assertSame(1, DemoUsdtLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame(0, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());

        $audit = AuditLog::query()->where('event', 'admin.deposit_approved')
            ->where('auditable_id', $deposit->id)
            ->firstOrFail();
        $this->assertSame('not_verified_demo', $audit->metadata['transaction_verification']);
        $this->assertSame('demo_usdt', $audit->metadata['credited_wallet']);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Excludes DEMO wallet credits')
            ->assertSee('0 USDT');

        $this->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('status', 'This deposit was already processed; no additional balance change was made.');

        $this->assertSame('200.00000000', $customer->fresh()->demo_usdt_balance);
        $this->assertSame(1, DemoUsdtLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'admin.deposit_approved')
            ->where('auditable_id', $deposit->id)->count());
    }

    public function test_demo_rejection_requires_no_reason_and_never_credits_either_wallet(): void
    {
        config(['deposits.demo_approval_enabled' => true]);
        $customer = User::factory()->create(['balance' => '5.00000000']);
        $deposit = $this->deposit($customer, '200.00', true, 'ddfdfd');

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), ['status' => 'rejected'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit rejected successfully.');

        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->rejection_reason);
        $this->assertSame('5.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00000000', $customer->fresh()->demo_usdt_balance);
        $this->assertSame(0, DemoUsdtLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
        $this->assertSame(0, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
    }

    public function test_demo_approval_is_denied_unless_explicitly_enabled(): void
    {
        config(['deposits.demo_approval_enabled' => false]);
        $customer = User::factory()->create();
        $deposit = $this->deposit($customer, '200.00', true, 'ddfdfd');

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('0.00000000', $customer->fresh()->demo_usdt_balance);
        $this->assertSame(0, DemoUsdtLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
    }

    public function test_only_admins_can_resolve_deposits(): void
    {
        $customer = User::factory()->create();
        $deposit = $this->deposit($customer, '20.00', true, 'ddfdfd');
        config(['deposits.demo_approval_enabled' => true]);

        $this->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertRedirect(route('admin.login'));

        $this->actingAs($customer, 'web')
            ->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertRedirect(route('admin.login'));

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('0.00000000', $customer->fresh()->demo_usdt_balance);
    }

    public function test_real_deposits_require_independent_verification_even_when_demo_approval_is_enabled(): void
    {
        config([
            'app.env' => 'production',
            'deposits.demo_approval_enabled' => true,
        ]);
        $customer = User::factory()->create();
        $deposit = $this->deposit($customer, '20.00', false, 'ddfdfd');

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.deposits.show', $deposit))
            ->put(route('admin.deposits.update', $deposit), ['status' => 'approved'])
            ->assertSessionHasErrors('status');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00000000', $customer->fresh()->demo_usdt_balance);
        $this->assertSame(0, BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->count());
    }

    public function test_legacy_deposits_remain_non_demo_and_keep_their_customer_relationship(): void
    {
        $customer = User::factory()->create();
        $deposit = $this->deposit($customer, '20.00', false, null);

        $this->assertFalse($deposit->fresh()->is_demo);
        $this->assertSame($customer->id, $deposit->fresh()->user->id);
        $this->assertSame('0.00000000', $customer->fresh()->demo_usdt_balance);

        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.deposits.update', $deposit), ['status' => 'rejected'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Deposit rejected successfully.');

        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertSame($customer->id, $deposit->fresh()->user_id);
    }

    private function deposit(User $customer, string $amount, bool $isDemo, ?string $transactionId): Deposit
    {
        $deposit = Deposit::create([
            'deposit_id' => 'DEP'.now()->format('Ymd').strtoupper(Str::random(10)),
            'user_id' => $customer->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'transaction_reference' => null,
            'transaction_hash' => $transactionId,
            'network_name' => 'BEP20',
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $deposit->forceFill(['is_demo' => $isDemo])->save();

        return $deposit->fresh();
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
