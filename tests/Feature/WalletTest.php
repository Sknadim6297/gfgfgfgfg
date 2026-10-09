<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Models\InrLedgerEntry;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Decimal;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_uses_recorded_usd_and_actual_inr_balances_and_customer_scoped_histories(): void
    {
        $customer = $this->userWithBalances(['balance' => '5000.00', 'inr_balance' => '1234.56']);
        $other = $this->userWithBalances(['balance' => '999.00', 'inr_balance' => '99999.00']);
        $ownDeposit = $this->deposit($customer, '100.00', 'OWNUTR0001');
        $otherDeposit = $this->deposit($other, '900.00', 'OTHERUTR001');
        $ownWithdrawal = $this->withdrawal($customer, '20.00', 'WDR-OWN-1');
        $otherWithdrawal = $this->withdrawal($other, '900.00', 'WDR-OTHER-1');

        $this->actingAs($customer, 'web')
            ->get(route('wallet'))
            ->assertOk()
            ->assertSee('$5,000.00')
            ->assertSee('₹1,234.56')
            ->assertSee($ownDeposit->deposit_id)
            ->assertSee($ownWithdrawal->request_reference)
            ->assertDontSee('OTHERUTR001')
            ->assertDontSee('WDR-OTHER-1')
            ->assertSee('app-shell', false)
            ->assertSee('wallet-primary-actions', false);
    }

    public function test_exchange_completion_debits_usd_and_credits_saved_inr_rate_snapshot_only_once(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = $this->userWithBalances(['balance' => '15000.00']);
        $exchange = $this->exchange($customer, '15000.00', '115.00000000', '1725000.00');
        $admin = $this->admin();

        $this->actingAs($customer, 'web')->get(route('wallet'))->assertSee('₹0.00');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.exchanges.update', $exchange), [
                'status' => 'processing',
                'transaction_reference' => '',
                'admin_notes' => '',
            ])->assertRedirect();

        $this->put(route('admin.exchanges.update', $exchange), [
            'status' => 'completed',
            'transaction_reference' => 'USDT-VERIFIED-REF',
            'admin_notes' => '',
        ])->assertRedirect();

        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame('1725000.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'debit',
            'amount' => '15000.00',
        ]);
        $this->assertDatabaseHas('inr_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'credit',
            'amount' => '1725000.00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_status_updated']);
    }

    public function test_customer_sell_request_keeps_usd_pending_and_saves_rate_plan_snapshot(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '15000.00']);
        $customer->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        $submissionKey = (string) Str::uuid();

        $quote = $this->actingAs($customer, 'web')
            ->post(route('exchange.requests.quote'), [
                'submission_key' => $submissionKey,
                'amount' => '15000.00',
            ])->assertOk();
        preg_match('/name="quote_token" value="([^"]+)"/', $quote->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'amount' => '15000.00',
            'quote_token' => $matches[1],
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();

        $exchange = ExchangeRequest::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $exchange->status);
        $this->assertSame('Prime Rate', $exchange->rate_plan_name);
        $this->assertSame(0, Decimal::compare((string) $exchange->exchange_rate, '115.00000000', 8));
        $this->assertSame(0, Decimal::compare((string) $exchange->inr_amount, '1725000.00', 2));
        $this->assertSame('15000.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00', $customer->fresh()->inr_balance);

        ExchangeRate::where('plan_key', 'prime')->update(['rate' => '1.00000000']);
        $this->assertSame(0, Decimal::compare((string) $exchange->fresh()->exchange_rate, '115.00000000', 8));
        $this->assertSame(0, Decimal::compare((string) $exchange->fresh()->inr_amount, '1725000.00', 2));
    }

    public function test_sell_request_uses_exact_decimal_rate_and_accepts_eight_decimal_usdt_amounts(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        ExchangeRate::where('plan_key', 'base')->update(['rate' => '100.12345678']);
        $customer = $this->userWithBalances(['balance' => '10.00']);
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => '1.23',
        ])->assertOk();
        preg_match('/name="quote_token" value="([^"]+)"/', $quote->getContent(), $matches);
        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'amount' => '1.23',
            'quote_token' => $matches[1],
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();

        $exchange = ExchangeRequest::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame(0, Decimal::compare((string) $exchange->exchange_rate, '100.12345678', 8));
        $this->assertSame(0, Decimal::compare((string) $exchange->inr_amount, '123.15', 2));

        $microKey = (string) Str::uuid();
        $microQuote = $this->post(route('exchange.requests.quote'), [
            'submission_key' => $microKey,
            'amount' => '1.231',
        ])->assertOk();
        preg_match('/name="quote_token" value="([^"]+)"/', $microQuote->getContent(), $microMatches);
        $this->assertNotEmpty($microMatches[1] ?? null);
        $this->post(route('exchange.requests.store'), [
            'submission_key' => $microKey,
            'amount' => '1.231',
            'quote_token' => $microMatches[1],
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();
        $this->assertSame(2, ExchangeRequest::where('user_id', $customer->id)->count());
    }

    public function test_withdrawal_reserves_inr_uses_saved_bank_snapshot_and_duplicate_submission_does_not_double_spend(): void
    {
        $customer = $this->customerWithBank(['inr_balance' => '2500.00']);
        $submissionKey = (string) Str::uuid();
        $payload = ['submission_key' => $submissionKey, 'amount' => '1250.25', 'payout_method' => 'bank', 'wallet_transaction_pin' => '1234'];

        $this->actingAs($customer, 'web')
            ->post(route('wallet.withdrawals.store'), $payload)
            ->assertRedirect();
        $request = WithdrawalRequest::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame('WDR-', substr($request->request_reference, 0, 4));
        $this->get(route('wallet.withdrawals.show', $request))
            ->assertOk()
            ->assertSee('pending admin review')
            ->assertDontSee('Download Invoice');
        $this->actingAs($customer, 'web')
            ->get(route('wallet.withdrawals.invoice', $request))
            ->assertNotFound();
        $this->get(route('wallet.withdrawals.create'))
            ->assertOk()
            ->assertSee('Available INR Balance')
            ->assertSee('Wallet Transaction PIN')
            ->assertSee('Account ••••••7890')
            ->assertDontSee('1234567890');
        $this->assertSame('Bank of Example', $request->bank_name);
        $this->assertSame('1234567890', $request->bank_account_number);
        $this->assertSame('1249.75', $customer->fresh()->inr_balance);

        $customer->account_number = '0000000000';
        $customer->save();
        $this->assertSame('1234567890', $request->fresh()->bank_account_number);

        $this->post(route('wallet.withdrawals.store'), $payload)->assertRedirect();
        $this->assertSame(1, WithdrawalRequest::where('user_id', $customer->id)->count());
        $this->assertSame('1249.75', $customer->fresh()->inr_balance);
        $this->assertSame(1, InrLedgerEntry::where('source_type', 'withdrawal_request')->where('entry_type', 'hold')->count());
    }

    public function test_withdrawal_reject_refunds_once_and_completion_requires_external_reference(): void
    {
        $customer = $this->customerWithBank(['inr_balance' => '1000.00']);
        $withdrawal = $this->withdrawal($customer, '300.00', 'WDR-TEST-1');
        $this->ledger($customer, $withdrawal, 'debit', '300.00');
        $customer->forceFill(['inr_balance' => '700.00'])->save();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'completed',
            'transaction_reference' => '',
            'admin_notes' => '',
        ])->assertUnprocessable();

        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'rejected',
            'transaction_reference' => '',
            'admin_notes' => 'Bank details could not be verified.',
            'rejection_reason' => 'We could not verify the payout destination.',
        ])->assertRedirect();
        $this->assertSame('1000.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseHas('inr_ledger_entries', [
            'source_type' => 'withdrawal_request',
            'source_id' => $withdrawal->id,
            'entry_type' => 'credit',
            'amount' => '300.00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.withdrawal_rejected']);
        $this->actingAs($customer, 'web')
            ->get(route('wallet.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('We could not verify the payout destination.');
    }

    public function test_cash_withdrawal_is_reserved_and_reviewed_without_bank_snapshots(): void
    {
        $customer = $this->userWithBalances(['inr_balance' => '500.00']);
        $this->actingAs($customer, 'web')
            ->post(route('wallet.withdrawals.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '125.00',
                'payout_method' => 'cash',
                'wallet_transaction_pin' => '1234',
            ])->assertRedirect();

        $withdrawal = WithdrawalRequest::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('cash', $withdrawal->payout_method);
        $this->assertNull($withdrawal->bank_account_number);
        $this->assertSame('375.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseHas('inr_ledger_entries', [
            'source_type' => 'withdrawal_request',
            'source_id' => $withdrawal->id,
            'entry_type' => 'hold',
            'amount' => '125.00',
        ]);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('Cash handling')
            ->assertSee('Cash')
            ->assertDontSee('Account number');
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'processing',
            'transaction_reference' => '',
            'admin_notes' => '',
        ])->assertRedirect();
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'completed',
            'transaction_reference' => 'CASH-PAYOUT-125',
            'admin_notes' => '',
        ])->assertRedirect();

        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertNotNull($withdrawal->fresh()->completed_at);
        $this->assertSame('375.00', $customer->fresh()->inr_balance);
        $this->assertSame(1, InrLedgerEntry::where('source_id', $withdrawal->id)->where('entry_type', 'hold')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_id', $withdrawal->id)->where('entry_type', 'capture')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.withdrawal_completed']);

        $this->actingAs($customer, 'web')
            ->get(route('wallet.withdrawals.invoice', $withdrawal))
            ->assertOk()
            ->assertSee('Withdrawal Invoice / Receipt')
            ->assertSee($withdrawal->request_reference)
            ->assertSee($customer->customer_id)
            ->assertSee('CASH-PAYOUT-125')
            ->assertSee('COMPLETED')
            ->assertDontSee('wallet_transaction_password_hash')
            ->assertDontSee('1234');
        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('wallet.withdrawals.invoice', $withdrawal))
            ->assertNotFound();
    }

    public function test_rejecting_a_new_withdrawal_releases_the_hold_once_and_shows_the_customer_reason(): void
    {
        $customer = $this->userWithBalances(['inr_balance' => '500.00']);
        $this->actingAs($customer, 'web')->post(route('wallet.withdrawals.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '125.00',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();
        $withdrawal = WithdrawalRequest::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('375.00', $customer->fresh()->inr_balance);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'rejected',
            'rejection_reason' => '',
        ])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'rejected',
            'rejection_reason' => 'Payout details require correction.',
        ])->assertRedirect();

        $this->assertSame('rejected', $withdrawal->fresh()->status);
        $this->assertSame('500.00', $customer->fresh()->inr_balance);
        $this->assertSame(1, InrLedgerEntry::where('source_id', $withdrawal->id)->where('entry_type', 'hold')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_id', $withdrawal->id)->where('entry_type', 'release')->count());
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'rejected',
            'rejection_reason' => 'Duplicate attempt.',
        ])->assertUnprocessable();
        $this->assertSame('500.00', $customer->fresh()->inr_balance);

        $this->actingAs($customer, 'web')
            ->get(route('wallet.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('Payout details require correction.')
            ->assertDontSee('Download Invoice');
    }

    public function test_withdrawal_rejects_zero_insufficient_balance_and_missing_bank_details(): void
    {
        $customer = $this->customerWithBank(['inr_balance' => '10.00']);
        $this->actingAs($customer, 'web');
        $key = (string) Str::uuid();

        $this->from(route('wallet'))->post(route('wallet.withdrawals.store'), [
            'submission_key' => $key,
            'amount' => '0.00',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertSessionHasErrors('amount');
        $this->from(route('wallet'))->post(route('wallet.withdrawals.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '10.01',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, WithdrawalRequest::count());

        $zeroBalance = $this->customerWithBank();
        $this->actingAs($zeroBalance, 'web')
            ->get(route('wallet'))
            ->assertSee('No INR balance available for withdrawal.')
            ->assertSee('disabled', false);
        $this->post(route('wallet.withdrawals.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '0.01',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertSessionHasErrors('amount');

        $noBank = $this->userWithBalances(['inr_balance' => '10.00']);
        $this->actingAs($noBank, 'web')
            ->post(route('wallet.withdrawals.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '1.00',
                'payout_method' => 'bank',
                'wallet_transaction_pin' => '1234',
            ])->assertSessionHasErrors('bank');
        $this->post(route('wallet.withdrawals.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '1.00',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();
        $this->assertSame(1, WithdrawalRequest::where('user_id', $noBank->id)->count());
        $this->assertSame('cash', WithdrawalRequest::where('user_id', $noBank->id)->value('payout_method'));
        $this->assertNull(WithdrawalRequest::where('user_id', $noBank->id)->value('bank_account_number'));
    }

    public function test_wallet_and_withdrawal_details_are_customer_scoped_and_admin_only_can_change_status(): void
    {
        $owner = $this->customerWithBank(['inr_balance' => '50.00']);
        $other = User::factory()->create();
        $withdrawal = $this->withdrawal($owner, '20.00', 'WDR-OWNER');

        $this->actingAs($other, 'web')
            ->get(route('wallet.withdrawals.show', $withdrawal))
            ->assertNotFound();
        $this->put(route('admin.withdrawals.update', $withdrawal), [
            'status' => 'rejected',
            'transaction_reference' => '',
            'admin_notes' => '',
        ])->assertRedirect(route('admin.login'));

        $this->actingAs($other, 'web')
            ->get(route('wallet'))
            ->assertDontSee('WDR-OWNER');
        $this->actingAs($owner, 'web')
            ->get(route('wallet.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('WDR-OWNER');
    }

    public function test_admin_withdrawal_pages_require_a_two_factor_verified_admin_session(): void
    {
        $customer = $this->customerWithBank(['inr_balance' => '75.00']);
        $withdrawal = $this->withdrawal($customer, '25.00', 'WDR-ADMIN-1');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.withdrawals.index'))
            ->assertRedirect(route('admin.2fa.challenge'));

        $this->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.withdrawals.index'))
            ->assertOk()
            ->assertSee('WDR-ADMIN-1');
        $this->get(route('admin.withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee('1234567890')
            ->assertSee('Available INR after reservation');
    }

    public function test_guest_cannot_submit_withdrawal_or_sell_request(): void
    {
        $this->post(route('wallet.withdrawals.store'), [])->assertRedirect(route('login'));
        $this->post(route('exchange.requests.store'), [])->assertRedirect(route('login'));
        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(0, ExchangeRequest::count());
    }

    public function test_wallet_shell_uses_profile_width_and_stacks_the_primary_actions(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')->get(route('wallet'))
            ->assertOk()
            ->assertSee('profile-primary-actions wallet-primary-actions', false)
            ->assertSee('bottom-nav app-shell', false)
            ->assertSee('USD Balance')
            ->assertSee('INR Balance')
            ->assertDontSee('Estimated INR Equivalent')
            ->assertDontSee('Withdrawals are not currently supported');

        $css = file_get_contents(public_path('assets/css/wallet.css'));
        $this->assertStringContainsString('width: calc(100% - 24px)', $css);
        $this->assertStringContainsString('max-width: 430px', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', file_get_contents(public_path('assets/css/profile-dashboard.css')));
    }

    private function deposit(User $user, string $amount, string $reference): Deposit
    {
        return Deposit::create([
            'deposit_id' => 'REF-'.Str::upper(Str::random(12)),
            'user_id' => $user->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'transaction_reference' => $reference,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    private function withdrawal(User $user, string $amount, string $reference): WithdrawalRequest
    {
        return WithdrawalRequest::create([
            'request_reference' => $reference,
            'user_id' => $user->id,
            'submission_key' => (string) Str::uuid(),
            'amount' => $amount,
            'payout_method' => 'bank',
            'bank_account_holder' => 'Wallet Customer',
            'bank_name' => 'Bank of Example',
            'bank_account_number' => '1234567890',
            'bank_ifsc_code' => 'EXMP0001234',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    private function exchange(User $user, string $amount, string $rate, string $inrAmount): ExchangeRequest
    {
        return ExchangeRequest::create([
            'user_id' => $user->id,
            'request_reference' => 'EXC-TEST-1',
            'submission_key' => (string) Str::uuid(),
            'rate_plan_name' => 'Prime Rate',
            'usdt_amount' => $amount,
            'exchange_rate' => $rate,
            'inr_amount' => $inrAmount,
            'status' => 'pending',
        ]);
    }

    private function ledger(User $user, WithdrawalRequest $withdrawal, string $type, string $amount): void
    {
        InrLedgerEntry::create([
            'user_id' => $user->id,
            'actor_user_id' => $user->id,
            'source_type' => 'withdrawal_request',
            'source_id' => $withdrawal->id,
            'entry_type' => $type,
            'amount' => $amount,
        ]);
    }

    private function customerWithBank(array $attributes = []): User
    {
        return $this->userWithBalances([
            'account_holder_name' => 'Wallet Customer',
            'bank_name' => 'Bank of Example',
            'account_number' => '1234567890',
            'ifsc_code' => 'EXMP0001234',
            'branch_name' => 'Example Branch',
            'account_type' => 'Savings',
            'bank_verification_status' => 'verified',
            ...$attributes,
        ]);
    }

    private function userWithBalances(array $attributes = []): User
    {
        $balance = $attributes['balance'] ?? '0.00';
        $inrBalance = $attributes['inr_balance'] ?? '0.00';
        $bankVerificationStatus = $attributes['bank_verification_status'] ?? null;
        unset($attributes['balance'], $attributes['inr_balance'], $attributes['bank_verification_status']);

        $user = User::factory()->create(['balance' => $balance, ...$attributes]);
        $user->forceFill([
            'wallet_transaction_password_hash' => Hash::make('1234'),
            'inr_balance' => $inrBalance,
            ...($bankVerificationStatus ? ['bank_verification_status' => $bankVerificationStatus] : []),
        ])->save();

        return $user;
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
