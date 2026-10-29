<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Models\InrLedgerEntry;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use App\Models\User;
use App\Notifications\ExchangeStatusUpdated;
use App\Services\ReferralCommissionService;
use App\Services\SellRequestService;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SellRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_form_uses_recorded_balance_and_sell_submission_completes_once(): void
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

        $this->assertSame('completed', $first->status);
        $this->assertSame('0.00000000', $customer->fresh()->balance);
        $this->assertSame('500000.00', $customer->fresh()->inr_balance);
        $this->assertSame('0.00000000', app(SellRequestService::class)->availableBalance($customer->fresh()));
        $this->assertDatabaseHas('balance_ledger_entries', [
            'source_type' => 'exchange_request',
            'source_id' => $first->id,
            'entry_type' => 'hold',
            'amount' => '5000.00000000',
        ]);
        $this->assertSame(1, BalanceLedgerEntry::where('source_id', $first->id)->where('entry_type', 'capture')->count());
        $this->assertSame(1, BalanceLedgerEntry::where('source_id', $first->id)->where('entry_type', 'debit')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_id', $first->id)->where('entry_type', 'credit')->count());

        $this->from(route('exchange.sell'))->post(route('exchange.requests.quote'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '5000',
        ])->assertRedirect(route('exchange.sell'))->assertSessionHasErrors('amount');
        $this->assertSame(1, ExchangeRequest::where('user_id', $customer->id)->count());
    }

    public function test_normal_customer_browsing_is_not_limited_by_financial_submission_throttles(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');

        for ($request = 0; $request < 10; $request++) {
            $this->get(route('home'))->assertOk();
        }
    }

    public function test_sell_throttle_returns_retry_after_but_allows_idempotent_retries_after_limit(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '100.00000000']);
        $firstSubmission = null;

        for ($index = 0; $index < 5; $index++) {
            $submissionKey = (string) Str::uuid();
            $quoteToken = $this->quoteSell($customer, '1', $submissionKey);
            $this->submitSellWithQuote($submissionKey, '1', $quoteToken)->assertRedirect();

            if ($index === 0) {
                $firstSubmission = [$submissionKey, $quoteToken];
            }
        }

        $limitedKey = (string) Str::uuid();
        $limitedQuote = $this->quoteSell($customer, '1', $limitedKey);
        $this->submitSellWithQuote($limitedKey, '1', $limitedQuote)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Please wait a moment');

        [$firstKey, $firstQuote] = $firstSubmission;
        $this->submitSellWithQuote($firstKey, '1', $firstQuote)
            ->assertRedirect()
            ->assertHeaderMissing('Retry-After');

        $this->submitSellWithQuote($firstKey, '1', $firstQuote, '9999')
            ->assertStatus(422)
            ->assertSee('Incorrect Wallet Transaction PIN.');
        for ($retry = 0; $retry < 3; $retry++) {
            $this->submitSellWithQuote($firstKey, '1', $firstQuote)->assertRedirect();
        }
        $this->submitSellWithQuote($firstKey, '1', $firstQuote)
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $foreignOwner = User::factory()->create(['balance' => '10.00000000']);
        $foreignKey = (string) Str::uuid();
        $foreignQuote = $this->quoteSell($foreignOwner, '1', $foreignKey);
        $this->submitSellWithQuote($foreignKey, '1', $foreignQuote)->assertRedirect();
        $customerForeignQuote = $this->quoteSell($customer, '1', $foreignKey);
        $this->submitSellWithQuote($foreignKey, '1', $customerForeignQuote)
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertSame(5, ExchangeRequest::query()->where('user_id', $customer->id)->count());
        $this->assertSame(5, BalanceLedgerEntry::query()
            ->where('user_id', $customer->id)
            ->where('entry_type', 'debit')
            ->count());
        $this->assertSame(5, InrLedgerEntry::query()
            ->where('user_id', $customer->id)
            ->where('entry_type', 'credit')
            ->count());
        $this->assertSame('95.00000000', $customer->fresh()->balance);
        $this->assertSame('500.00', $customer->fresh()->inr_balance);

        $this->withHeader('Accept', 'application/json')
            ->post(route('exchange.requests.store'), [
                'submission_key' => $limitedKey,
                'quote_token' => $limitedQuote,
                'amount' => '1',
                'wallet_transaction_pin' => '1234',
            ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_guest_cannot_use_an_existing_customers_submission_key_to_skip_authentication(): void
    {
        $owner = User::factory()->create(['balance' => '5.00000000']);
        $exchange = $this->submitSell($owner, '1');

        auth('web')->logout();
        $this->post(route('exchange.requests.store'), [
            'submission_key' => $exchange->submission_key,
            'quote_token' => 'invalid',
            'amount' => '1',
            'wallet_transaction_pin' => '1234',
        ])->assertRedirect(route('login'));
    }

    public function test_new_completed_sells_qualify_referral_and_credit_each_configured_level_once(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $levelOne = User::factory()->create();
        $levelTwo = User::factory()->create(['referred_by_user_id' => $levelOne->id]);
        $levelThree = User::factory()->create(['referred_by_user_id' => $levelTwo->id]);
        $customer = User::factory()->create([
            'balance' => '1000.00000000',
            'referred_by_user_id' => $levelThree->id,
        ]);
        ReferralCommissionLevel::query()->where('level', 1)->update(['amount' => '1777.77']);
        ReferralCommissionLevel::query()->where('level', 2)->update(['is_active' => false]);
        ReferralCommissionLevel::query()->where('level', 3)->update(['amount' => '222.22']);

        $first = $this->submitSell($customer, '250', false);
        $this->assertSame('completed', $first->status);
        $this->assertNull($first->referralQualification);
        $this->assertSame(0, ReferralQualification::query()->count());

        $second = $this->submitSell($customer->fresh(), '250', false);
        $qualification = ReferralQualification::query()->sole();
        $this->assertSame($second->id, $qualification->qualifying_exchange_request_id);
        $this->assertSame('500.00000000', $qualification->cumulative_usdt_equivalent);
        $this->assertSame([1, 3], ReferralCommission::query()->where('qualified_user_id', $customer->id)->orderBy('level')->pluck('level')->all());
        $this->assertSame('222.22', $levelOne->fresh()->inr_balance);
        $this->assertSame('0.00', $levelTwo->fresh()->inr_balance);
        $this->assertSame('1777.77', $levelThree->fresh()->inr_balance);
        $this->assertSame('500.00000000', $customer->fresh()->balance);
        $this->assertSame('50000.00', $customer->fresh()->inr_balance);
        $this->assertSame(1, BalanceLedgerEntry::where('source_id', $second->id)->where('entry_type', 'debit')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_type', 'exchange_request')->where('source_id', $second->id)->where('entry_type', 'credit')->count());

        DB::transaction(fn () => app(ReferralCommissionService::class)->processCompletedSell($second->fresh()));
        $this->assertSame(1, ReferralQualification::query()->where('qualified_user_id', $customer->id)->count());
        $this->assertSame(2, ReferralCommission::query()->where('qualified_user_id', $customer->id)->count());
        $this->assertSame('1777.77', ReferralCommission::query()->where('qualified_user_id', $customer->id)->where('level', 1)->sole()->amount);
        $this->assertSame('222.22', ReferralCommission::query()->where('qualified_user_id', $customer->id)->where('level', 3)->sole()->amount);
        $this->assertSame('222.22', $levelOne->fresh()->inr_balance);

        $this->actingAs($levelThree, 'web')
            ->get(route('profile.referrals'))
            ->assertOk()
            ->assertSee($second->request_reference)
            ->assertSee('₹1,777.77');
        $this->get(route('profile.referrals.member', $customer))
            ->assertOk()
            ->assertSee($second->request_reference)
            ->assertSee('₹1,777.77')
            ->assertSee('INR ledger #');
    }

    public function test_previously_completed_unmarked_sells_do_not_count_toward_new_referral_qualification(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $referrer = User::factory()->create();
        $customer = User::factory()->create([
            'balance' => '100.00000000',
            'referred_by_user_id' => $referrer->id,
        ]);
        $historical = ExchangeRequest::create([
            'user_id' => $customer->id,
            'request_reference' => 'QPMSELLHISTORICAL',
            'rate_plan_name' => 'Historical rate',
            'usdt_amount' => '490.00000000',
            'exchange_rate' => '100.00000000',
            'inr_amount' => '49000.00',
            'available_usd_before' => '490.00000000',
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);
        BalanceLedgerEntry::create([
            'user_id' => $customer->id,
            'source_type' => 'exchange_request',
            'source_id' => $historical->id,
            'entry_type' => 'debit',
            'amount' => '490.00000000',
        ]);
        InrLedgerEntry::create([
            'user_id' => $customer->id,
            'source_type' => 'exchange_request',
            'source_id' => $historical->id,
            'entry_type' => 'credit',
            'amount' => '49000.00',
        ]);

        $this->submitSell($customer, '20', false);

        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
        $this->assertNull($historical->fresh()->referral_eligible_at);
    }

    public function test_referral_credit_failure_rolls_back_sell_conversion_and_every_wallet_ledger_entry(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $referrer = User::factory()->create();
        $customer = User::factory()->create([
            'balance' => '500.00000000',
            'referred_by_user_id' => $referrer->id,
        ]);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_referral_credit BEFORE INSERT ON inr_ledger_entries
WHEN NEW.source_type = 'referral_commission'
BEGIN
    SELECT RAISE(ABORT, 'forced referral ledger failure');
END
SQL);

        try {
            $submissionKey = (string) Str::uuid();
            $quoteToken = $this->quoteSell($customer, '500', $submissionKey);
            $response = $this->submitSellWithQuote($submissionKey, '500', $quoteToken);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_referral_credit');
        }

        $response->assertServerError();
        $this->assertDatabaseCount('exchange_requests', 0);
        $this->assertDatabaseCount('balance_ledger_entries', 0);
        $this->assertDatabaseCount('inr_ledger_entries', 0);
        $this->assertSame('500.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00', $customer->fresh()->inr_balance);
        $this->assertSame(0, ReferralQualification::query()->count());
        $this->assertSame(0, ReferralCommission::query()->count());
    }

    public function test_invalid_and_insufficient_sell_requests_create_no_financial_records(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '10.00000000']);
        $this->actingAs($customer, 'web');
        $submissionKey = (string) Str::uuid();

        $this->from(route('exchange.sell'))->post(route('exchange.requests.quote'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '0',
        ])->assertRedirect(route('exchange.sell'))->assertSessionHasErrors('amount');
        $this->from(route('exchange.sell'))->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => '10.00000001',
        ])->assertRedirect(route('exchange.sell'))->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('exchange_requests', 0);
        $this->assertDatabaseCount('balance_ledger_entries', 0);
        $this->assertDatabaseCount('inr_ledger_entries', 0);
        $this->assertSame('10.00000000', $customer->fresh()->balance);
        $this->assertSame('0.00', $customer->fresh()->inr_balance);
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
            ->assertSee('300')
            ->assertDontSee('300.000000')
            ->assertSee('class="admin-sell-usdt-unit">USDT</small>', false)
            ->assertSee('₹100')
            ->assertSee('<small>/ USDT</small>', false)
            ->assertSee('₹30,000.00');
    }

    public function test_admin_sell_summary_aggregates_all_time_usdt_and_inr_separately_by_workflow_status(): void
    {
        $customer = User::factory()->create();
        $this->makeExchange($customer, '0.12345678', '12.35', 'pending');
        $this->makeExchange($customer, '0.87654322', '87.65', 'processing');
        $this->makeExchange($customer, '2.00000000', '200.00', 'approved');
        $this->makeExchange($customer, '3.00000000', '300.00', 'completed');
        $this->makeExchange($customer, '4.00000000', '400.00', 'rejected');

        $response = $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.index', ['status' => 'rejected']));

        $response->assertOk()
            ->assertSee('All-time totals across every sell request; filters only affect the list below.')
            ->assertSee('10 USDT')
            ->assertSee('₹1,000.00 INR · 5 requests')
            ->assertSee('1 USDT')
            ->assertSee('₹100.00 INR · 2 requests')
            ->assertSee('5 USDT')
            ->assertSee('₹500.00 INR · 2 requests')
            ->assertSee('4 USDT')
            ->assertSee('₹400.00 INR · 1 requests')
            ->assertSee('QPMSELL');
    }

    public function test_sell_request_summary_remains_accessible_only_to_verified_admins(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.exchanges.index'))
            ->assertOk();
    }

    public function test_empty_sell_request_summary_displays_zero_amounts(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.index'))
            ->assertOk()
            ->assertSee('0 USDT')
            ->assertSee('₹0.00 INR · 0 requests');
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
        ])->assertOk()
            ->assertSee('INR on confirmation')
            ->assertSee('Confirming converts your USDT at the displayed rate')
            ->assertSee('Completed');
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

    public function test_sell_submission_completes_conversion_once_and_records_wallet_history(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '5000.00000000']);
        $exchange = $this->submitSell($customer, '5000');
        $this->actingAs($customer, 'web')
            ->get('/exchange/'.$exchange->request_reference.'/invoice')
            ->assertNotFound();
        $this->assertSame('completed', $exchange->fresh()->status);
        $this->assertNotNull($exchange->fresh()->completed_at);
        $this->assertNull($exchange->fresh()->approved_at);
        $this->assertNull($exchange->fresh()->approved_by_user_id);
        $this->assertNull($exchange->fresh()->transaction_reference);
        $this->post('/admin/exchanges/'.$exchange->request_reference.'/approve')->assertNotFound();

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.show', $exchange))
            ->assertOk()
            ->assertSee($customer->customer_id)
            ->assertSee('Current available USD')
            ->assertSee('Conversion completed')
            ->assertDontSee('> Approve</button>', false)
            ->assertDontSee('Reject Request')
            ->assertDontSee('Payment Method')
            ->assertDontSee('Bank Account')
            ->assertDontSee('Cash');

        $this->get(route('admin.exchanges.index'))
            ->assertOk()
            ->assertSee('Completed / reviewed')
            ->assertDontSee('Payment');

        $exchange->refresh();
        $this->assertSame('completed', $exchange->status);
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
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SELL_REQUEST_AUTO_COMPLETED',
            'actor_user_id' => null,
            'subject_user_id' => $customer->id,
        ]);
        $this->assertSame(1, AuditLog::where('auditable_type', ExchangeRequest::class)
            ->where('auditable_id', $exchange->id)
            ->where('event', 'SELL_REQUEST_AUTO_COMPLETED')
            ->count());
        $this->get(route('admin.users.show', $customer))
            ->assertOk()
            ->assertSee('5,000 USDT')
            ->assertSee('₹100 / USDT')
            ->assertSee('₹500,000.00')
            ->assertDontSee('5,000.00000000');
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.exchanges.show', $exchange))
            ->assertOk()
            ->assertSee('Sell Request Auto Completed')
            ->assertSee('Customer ID')
            ->assertSee('USDT Amount')
            ->assertSee('5,000 USDT')
            ->assertSee('Exchange Rate')
            ->assertSee('₹100 / USDT')
            ->assertSee('INR Amount')
            ->assertSee('₹500,000.00')
            ->assertDontSee('"usdt_amount"');

        $this->assertSame(1, BalanceLedgerEntry::where('source_id', $exchange->id)->where('entry_type', 'debit')->count());
        $this->assertSame(1, InrLedgerEntry::where('source_id', $exchange->id)->where('entry_type', 'credit')->count());
        $notification = $customer->notifications()
            ->where('type', ExchangeStatusUpdated::class)
            ->sole();
        $this->assertSame('completed', $notification->data['status']);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get('/admin/exchanges/'.$exchange->request_reference.'/invoice')
            ->assertNotFound();
        $this->actingAs($customer, 'web')->get(route('exchange.requests.show', $exchange))
            ->assertOk()
            ->assertSee($exchange->request_reference)
            ->assertSee('Your USDT conversion is complete.')
            ->assertSee('was credited to your INR Wallet')
            ->assertSee('Completed')
            ->assertDontSee('Invoice');
        $this->get(route('profile.exchanges'))
            ->assertOk()
            ->assertSee($exchange->request_reference)
            ->assertSee('5,000 USDT')
            ->assertSee('View Details')
            ->assertDontSee('Invoice');
    }

    public function test_wallet_ledger_failure_rolls_back_conversion_status_and_both_wallet_balances(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create([
            'balance' => '100.00000000',
            'inr_balance' => '25.00',
            'wallet_transaction_password_hash' => Hash::make('1234'),
        ]);
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => '10',
        ])->assertOk();
        $quoteToken = $this->quoteToken($quote->getContent());
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_sell_inr_credit BEFORE INSERT ON inr_ledger_entries
WHEN NEW.source_type = 'exchange_request'
BEGIN
    SELECT RAISE(ABORT, 'forced sell INR ledger failure');
END
SQL);

        try {
            $this->withoutExceptionHandling()->post(route('exchange.requests.store'), [
                'submission_key' => $submissionKey,
                'quote_token' => $quoteToken,
                'amount' => '10',
                'wallet_transaction_pin' => '1234',
            ]);
            $this->fail('The forced INR ledger failure should abort the conversion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced sell INR ledger failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_sell_inr_credit');
        }

        $this->assertSame('100.00000000', $customer->fresh()->balance);
        $this->assertSame('25.00', $customer->fresh()->inr_balance);
        $this->assertDatabaseCount('exchange_requests', 0);
        $this->assertDatabaseCount('balance_ledger_entries', 0);
        $this->assertDatabaseCount('inr_ledger_entries', 0);
    }

    public function test_admin_rejection_requires_reason_releases_reservation_and_never_credits_inr(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '2500.00000000']);
        $exchange = ExchangeRequest::create([
            'user_id' => $customer->id,
            'submission_key' => (string) Str::uuid(),
            'rate_plan_key' => 'base',
            'rate_plan_name' => 'Base Rate',
            'usdt_amount' => '2000.00000000',
            'exchange_rate' => '100.00000000',
            'inr_amount' => '200000.00',
            'available_usd_before' => '2500.00000000',
            'status' => 'pending',
        ]);
        BalanceLedgerEntry::create([
            'user_id' => $customer->id,
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'actor_user_id' => $customer->id,
            'entry_type' => 'hold',
            'amount' => '2000.00000000',
        ]);
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
        $this->assertSame('completed', $exchange->status);
        $this->assertArrayNotHasKey('payment_method', $exchange->getAttributes());
        $this->actingAs($customer, 'web')->get(route('exchange.requests.show', $exchange))
            ->assertOk()
            ->assertSee('Your USDT conversion is complete.')
            ->assertSee('Reference ID')
            ->assertSee($exchange->request_reference)
            ->assertSee('Completed')
            ->assertSee('Applied Rate')
            ->assertSee('USDT Sold')
            ->assertSee('INR Amount')
            ->assertSee('View Exchange History')
            ->assertSee('Back to Exchange')
            ->assertDontSee('awaiting admin approval')
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

    private function submitSell(User $customer, string $amount, bool $verifyDuplicate = true): ExchangeRequest
    {
        $submissionKey = (string) Str::uuid();
        $quoteToken = $this->quoteSell($customer, $amount, $submissionKey);
        $this->submitSellWithQuote($submissionKey, $amount, $quoteToken)->assertRedirect();
        if ($verifyDuplicate) {
            $this->submitSellWithQuote($submissionKey, $amount, $quoteToken)->assertRedirect();
        }

        return ExchangeRequest::query()->where('user_id', $customer->id)->where('submission_key', $submissionKey)->firstOrFail();
    }

    private function quoteSell(User $customer, string $amount, string $submissionKey): string
    {
        if (! $customer->hasWalletTransactionPin()) {
            $customer->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        }
        $quote = $this->actingAs($customer, 'web')->post(route('exchange.requests.quote'), [
            'submission_key' => $submissionKey,
            'amount' => $amount,
        ])->assertOk();

        return $this->quoteToken($quote->getContent());
    }

    private function submitSellWithQuote(
        string $submissionKey,
        string $amount,
        string $quoteToken,
        string $transactionPin = '1234',
    ): TestResponse {
        return $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'quote_token' => $quoteToken,
            'amount' => $amount,
            'wallet_transaction_pin' => $transactionPin,
        ]);
    }

    private function quoteToken(string $html): string
    {
        preg_match('/name="quote_token" value="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'The sell confirmation must include its encrypted quote token.');

        return $matches[1];
    }

    private function makeExchange(User $customer, string $usdt, string $inr, string $status): ExchangeRequest
    {
        return ExchangeRequest::create([
            'user_id' => $customer->id,
            'rate_plan_name' => 'Test rate',
            'usdt_amount' => $usdt,
            'exchange_rate' => '100.00000000',
            'inr_amount' => $inr,
            'status' => $status,
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
