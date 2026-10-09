<?php

namespace Tests\Feature;

use App\Models\BalanceLedgerEntry;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Models\InrLedgerEntry;
use App\Models\User;
use App\Services\SellRequestService;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_form_uses_recorded_balance_and_pending_requests_reserve_usd(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '5000.00000000']);
        $this->actingAs($customer, 'web')->get(route('exchange.sell'))
            ->assertOk()
            ->assertSee('Available USD Balance')
            ->assertSee('Sell Amount')
            ->assertDontSee('Payment Method')
            ->assertDontSee('Bank Account')
            ->assertDontSee('Account Number')
            ->assertDontSee('IFSC')
            ->assertDontSee('Cash');
        $first = $this->submitSell($customer, '5000');

        $this->assertSame('pending', $first->status);
        $this->assertSame('5000.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00000000', app(SellRequestService::class)->availableBalance($customer->fresh()));
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $first->id,
            'entry_type' => 'hold',
            'amount' => '5000.00000000',
        ]);

        $this->from(route('exchange.sell'))->post(route('exchange.requests.quote'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '5000',
        ])->assertRedirect(route('exchange.sell'))->assertSessionHasErrors('amount');
        $this->assertSame(1, ExchangeRequest::where('user_id', $customer->id)->count());
    }

    public function test_admin_sell_list_formats_the_entered_usdt_amount_and_rate_with_clear_units(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '500.00000000']);
        $exchange = $this->submitSell($customer, '300');
        $this->assertSame('300.00000000', $exchange->usdt_amount);
        $this->assertSame('100.00000000', $exchange->exchange_rate);
        $this->assertSame('30000.00', $exchange->inr_amount);

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.index'))
            ->assertOk()
            ->assertSee('300.000000')
            ->assertSee('class="admin-sell-usdt-unit">USDT</small>', false)
            ->assertSee('₹100')
            ->assertSee('<small>/ USDT</small>', false)
            ->assertSee('₹30,000.00');
    }

    public function test_sell_submission_snapshots_current_rate_and_requires_a_fresh_confirmation_if_it_changes(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '1000.00000000']);
        $customer->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => '100',
        ])->assertOk();
        $token = $this->quoteToken($quote->getContent());

        ExchangeRate::where('plan_key', 'base')->update(['rate' => '102.00000000']);
        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'quote_token' => $token,
            'amount' => '100',
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect(route('exchange.sell'))
            ->assertSessionHas('rate_notice');
        $this->assertDatabaseCount('exchange_requests', 0);

        $request = $this->submitSell($customer, '100');
        $this->assertSame('102.00000000', $request->exchange_rate);
        $this->assertSame('10200.00', $request->inr_amount);
    }

    public function test_admin_approval_finalizes_usd_once_and_sell_never_has_an_invoice(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '5000.00000000']);
        $exchange = $this->submitSell($customer, '5000');
        $this->actingAs($customer, 'web')
            ->get('/exchange/'.$exchange->request_reference.'/invoice')
            ->assertNotFound();
        $this->actingAs($customer, 'web')
            ->post(route('admin.exchanges.approve', $exchange))
            ->assertRedirect(route('admin.login'));
        $this->assertSame('pending', $exchange->fresh()->status);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.show', $exchange))
            ->assertOk()
            ->assertSee($customer->customer_id)
            ->assertSee('Current available USD')
            ->assertSee('Approve')
            ->assertDontSee('Payment Method')
            ->assertDontSee('Bank Account')
            ->assertDontSee('Cash');

        $this->get(route('admin.exchanges.index'))
            ->assertOk()
            ->assertSee('Reviewed')
            ->assertDontSee('Payment');

        $this->post(route('admin.exchanges.approve', $exchange))
            ->assertRedirect();

        $exchange->refresh();
        $this->assertSame('approved', $exchange->status);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame('500000.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'capture',
            'amount' => '5000.00000000',
        ]);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'debit',
            'amount' => '5000.00000000',
        ]);
        $this->assertDatabaseHas('inr_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'credit',
            'amount' => '500000.00',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'SELL_REQUEST_APPROVED']);

        $this->post(route('admin.exchanges.approve', $exchange))->assertConflict();
        $this->assertSame(1, BalanceLedgerEntry::where('source_id', $exchange->id)->where('entry_type', 'debit')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_id', $exchange->id)->where('entry_type', 'credit')->count());

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get('/admin/exchanges/'.$exchange->request_reference.'/invoice')
            ->assertNotFound();
        $this->actingAs($customer, 'web')->get(route('exchange.requests.show', $exchange))
            ->assertOk()
            ->assertSee($exchange->request_reference)
            ->assertSee('Your sell request has been approved.')
            ->assertSee('was credited to your INR Wallet')
            ->assertSee('Approved')
            ->assertDontSee('Invoice');
        $this->get(route('profile.exchanges'))
            ->assertOk()
            ->assertSee($exchange->request_reference)
            ->assertSee('View Details')
            ->assertDontSee('Invoice');
    }

    public function test_admin_rejection_requires_reason_releases_reservation_and_never_credits_inr(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '2500.00000000']);
        $exchange = $this->submitSell($customer, '2000');
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->post(route('admin.exchanges.reject', $exchange), [])
            ->assertSessionHasErrors('reason');
        $this->post(route('admin.exchanges.reject', $exchange), ['reason' => 'Rate snapshot requires review.'])
            ->assertRedirect();

        $exchange->refresh();
        $this->assertSame('rejected', $exchange->status);
        $this->assertSame('Rate snapshot requires review.', $exchange->rejection_reason);
        $this->assertSame('2500.00000000', app(SellRequestService::class)->availableBalance($customer->fresh()));
        $this->assertSame('0.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'release',
            'amount' => '2000.00000000',
        ]);
        $this->assertDatabaseMissing('inr_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'SELL_REQUEST_REJECTED']);

        $this->post(route('admin.exchanges.approve', $exchange))->assertConflict();
        $this->actingAs($customer, 'web')
            ->get('/exchange/'.$exchange->request_reference.'/invoice')
            ->assertNotFound();
        $this->get(route('exchange.requests.show', $exchange))
            ->assertOk()
            ->assertSee('Your request was rejected.')
            ->assertSee('Rate snapshot requires review.')
            ->assertDontSee('Invoice');
    }

    public function test_sell_does_not_require_or_snapshot_bank_details(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create([
            'balance' => '100.00000000',
            'bank_verification_status' => 'not_submitted',
        ]);
        $this->actingAs($customer, 'web')->get(route('exchange.sell'))
            ->assertOk()
            ->assertDontSee('Bank')
            ->assertDontSee('Cash');

        $exchange = $this->submitSell($customer, '50');
        $this->assertSame('pending', $exchange->status);
        $this->assertArrayNotHasKey('payment_method', $exchange->getAttributes());
        $this->actingAs($customer, 'web')->get(route('exchange.requests.show', $exchange))
            ->assertOk()
            ->assertSee('Your sell request has been submitted and is awaiting review.')
            ->assertSee('Reference ID')
            ->assertSee($exchange->request_reference)
            ->assertSee('Pending')
            ->assertSee('Applied Rate')
            ->assertSee('USDT Sold')
            ->assertSee('INR Amount')
            ->assertSee('View Exchange History')
            ->assertSee('Back to Exchange')
            ->assertSee('awaiting admin approval')
            ->assertSee('credited to your INR Wallet')
            ->assertDontSee('Invoice')
            ->assertDontSee('Bank')
            ->assertDontSee('Cash')
            ->assertDontSee('IFSC')
            ->assertDontSee('Payment Method');
    }

    public function test_customer_cannot_view_another_customers_request_by_public_reference(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $owner = User::factory()->create(['balance' => '100.00000000']);
        $other = User::factory()->create(['balance' => '100.00000000']);
        $exchange = $this->submitSell($owner, '25');

        $this->actingAs($other, 'web')
            ->get(route('exchange.requests.show', $exchange))
            ->assertNotFound();
        $this->get(route('profile.exchanges'))
            ->assertOk()
            ->assertDontSee($exchange->request_reference);
    }

    private function submitSell(User $customer, string $amount): ExchangeRequest
    {
        if (! $customer->hasWalletTransactionPin()) {
            $customer->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        }
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => $amount,
        ])->assertOk();

        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'quote_token' => $this->quoteToken($quote->getContent()),
            'amount' => $amount,
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();
        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'quote_token' => $this->quoteToken($quote->getContent()),
            'amount' => $amount,
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect();

        return ExchangeRequest::query()->where('user_id', $customer->id)->where('submission_key', $submissionKey)->firstOrFail();
    }

    private function quoteToken(string $html): string
    {
        preg_match('/name="quote_token" value="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'The sell confirmation must include its encrypted quote token.');

        return $matches[1];
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
