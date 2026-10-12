<?php

namespace Tests\Feature;

use App\Mail\WalletTransactionPinChanged;
use App\Mail\WalletTransactionPinOtp;
use App\Models\User;
use App\Models\WalletPinOtp;
use App\Services\WalletTransactionPinService;
use Database\Seeders\ExchangeRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class WalletTransactionPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sets_a_hashed_transaction_pin_only_after_email_otp_verification(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Wallet Transaction PIN')
            ->assertSee('Not Set')
            ->assertSee('data-pin-otp-digit', false)
            ->assertSee('wallet-pin-otp-digit-6')
            ->assertSee('Verify OTP')
            ->assertSee('Resend OTP')
            ->assertSee('data-pin-step="send"', false)
            ->assertSee('data-pin-step="verify" hidden', false)
            ->assertSee('data-pin-step="save" hidden', false)
            ->assertSee('data-pin-testing-otp', false)
            ->assertSee('data-pin-new', false)
            ->assertSee('data-pin-confirm', false)
            ->assertDontSee('data-pin-success-message')
            ->assertDontSee('data-pin-message')
            ->assertDontSee('data-pin-error');

        $sent = $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $this->assertStringContainsString('verification code was sent to', $sent->json('message'));
        $this->assertStringContainsString(substr(strstr($user->email, '@'), 1), $sent->json('message'));
        $code = $this->latestOtpCode();
        $this->assertSame($code, $sent->json('testing_otp'));

        $otp = WalletPinOtp::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($code, $otp->otp_hash);
        $this->assertTrue(Hash::check($code, $otp->otp_hash));

        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_set',
            'wallet_transaction_pin' => '1234',
            'wallet_transaction_pin_confirmation' => '1234',
        ])->assertUnprocessable();
        $this->assertNull($user->fresh()->wallet_transaction_password_hash);

        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertOk();
        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_set',
            'wallet_transaction_pin' => '1234',
            'wallet_transaction_pin_confirmation' => '1234',
        ])->assertOk()->assertJsonPath('message', 'Wallet Transaction PIN set successfully.');

        $user->refresh();
        $this->assertTrue($user->hasWalletTransactionPin());
        $this->assertTrue(Hash::check('1234', $user->wallet_transaction_password_hash));
        $this->assertNotSame('1234', $user->wallet_transaction_password_hash);
        $this->assertNotNull($otp->fresh()->consumed_at);
        Mail::assertSent(WalletTransactionPinChanged::class);

        $this->actingAs($user, 'web')
            ->get(route('profile'))
            ->assertSee('Active');
    }

    public function test_otp_is_user_and_purpose_bound_expires_and_can_only_be_used_once(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $code = $this->latestOtpCode();

        $this->actingAs($other, 'web')
            ->postJson(route('wallet-pin.otp.verify'), [
                'purpose' => 'wallet_password_set',
                'code' => $code,
            ])->assertUnprocessable();

        $this->actingAs($owner, 'web')
            ->postJson(route('wallet-pin.otp.verify'), [
                'purpose' => 'wallet_password_reset',
                'code' => $code,
            ])->assertUnprocessable();

        $this->travel(11)->minutes();
        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('wallet_pin_otp')
            ->assertJsonPath('errors.wallet_pin_otp.0', 'This verification code has expired. Please request a new code.');

        $this->travelBack();
        $configured = User::factory()->create();
        $configured->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        $this->actingAs($configured, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertUnprocessable();
        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_reset'])
            ->assertOk();
    }

    public function test_pin_setup_resumes_after_a_page_reload_with_database_sessions(): void
    {
        Mail::fake();
        config(['session.driver' => 'database']);
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $code = $this->latestOtpCode();

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('data-pending-purpose="wallet_password_set"', false)
            ->assertSee('data-pin-step="verify"', false);

        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertOk();

        $otp = WalletPinOtp::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($otp->verified_at);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('data-verified-purpose="wallet_password_set"', false)
            ->assertSee('data-pending-purpose=""', false)
            ->assertSee('Email verified');

        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_set',
            'wallet_transaction_pin' => '2468',
            'wallet_transaction_pin_confirmation' => '2468',
        ])->assertOk();

        $this->assertTrue(Hash::check('2468', $user->fresh()->wallet_transaction_password_hash));
        $this->assertNotNull($otp->fresh()->consumed_at);
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    }

    public function test_expired_verified_otp_returns_an_actionable_error_without_consuming_it(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $code = $this->latestOtpCode();

        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertOk();

        $this->travel(11)->minutes();
        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_set',
            'wallet_transaction_pin' => '2468',
            'wallet_transaction_pin_confirmation' => '2468',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.wallet_pin_otp.0', 'This verification code has expired. Please request a new code.');

        $this->assertNull($user->fresh()->wallet_transaction_password_hash);
        $this->assertNull(WalletPinOtp::query()->where('user_id', $user->id)->value('consumed_at'));
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('data-pending-purpose=""', false)
            ->assertSee('data-verified-purpose=""', false)
            ->assertSee('data-pin-send', false);
    }

    public function test_repeated_pin_save_cannot_reuse_the_consumed_otp_or_change_the_pin_again(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->forceFill(['wallet_transaction_password_hash' => Hash::make('1357')])->save();
        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_change'])
            ->assertOk();
        $code = $this->latestOtpCode();

        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_change',
            'code' => $code,
        ])->assertOk();

        $payload = [
            'purpose' => 'wallet_password_change',
            'wallet_transaction_pin' => '2468',
            'wallet_transaction_pin_confirmation' => '2468',
        ];
        $this->postJson(route('wallet-pin.save'), $payload)->assertOk();
        $savedHash = $user->fresh()->wallet_transaction_password_hash;

        $this->postJson(route('wallet-pin.save'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.wallet_pin_otp.0', 'This verification code has already been used. Please request a new code.');

        $this->assertSame($savedHash, $user->fresh()->wallet_transaction_password_hash);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_consumed_otp_is_reported_as_already_used(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        $this->actingAs($user, 'web');

        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_reset'])
            ->assertOk();
        $code = $this->latestOtpCode();
        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_reset',
            'code' => $code,
        ])->assertOk();
        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_reset',
            'wallet_transaction_pin' => '5678',
            'wallet_transaction_pin_confirmation' => '5678',
        ])->assertOk();

        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_reset',
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.wallet_pin_otp.0', 'This verification code has already been used. Please request a new code.');
    }

    public function test_testing_otp_is_not_disclosed_when_app_environment_is_production(): void
    {
        Mail::fake();
        config(['app.env' => 'production']);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk()
            ->assertJsonMissingPath('testing_otp');

        $code = $this->latestOtpCode();
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertDatabaseCount('wallet_pin_otps', 1);
    }

    public function test_resending_otp_consumes_the_previous_code_and_sends_a_new_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $previousOtp = WalletPinOtp::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();

        $this->assertNotNull($previousOtp->fresh()->consumed_at);
        $this->assertSame(2, WalletPinOtp::query()->where('user_id', $user->id)->count());
        Mail::assertSent(WalletTransactionPinOtp::class, 2);
        $code = $this->latestOtpCode();
        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertOk();
    }

    public function test_otp_send_limit_returns_retry_json_then_allows_requests_again(): void
    {
        Mail::fake();
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('wallet-pin.otp.send');
        $this->assertFalse(collect($route->gatherMiddleware())->contains(
            fn (string $middleware): bool => str_starts_with($middleware, 'route.throttle:'),
        ));
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        for ($requestNumber = 1; $requestNumber <= 5; $requestNumber++) {
            $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
                ->assertOk()
                ->assertJsonMissingPath('retry_after');
        }
        Mail::assertSent(WalletTransactionPinOtp::class, 5);

        $limited = $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertStatus(429)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message', 'retry_after'])
            ->assertHeader('Retry-After');
        $retryAfter = $limited->json('retry_after');
        $this->assertIsInt($retryAfter);
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(600, $retryAfter);
        $this->assertSame((string) $retryAfter, $limited->headers->get('Retry-After'));
        $this->assertStringContainsString('Too many verification code requests.', $limited->json('message'));
        $this->assertStringNotContainsString('<html', strtolower($limited->getContent()));
        Mail::assertSent(WalletTransactionPinOtp::class, 5);

        $otherCustomer = User::factory()->create();
        $this->actingAs($otherCustomer, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();

        $this->travel(601)->seconds();
        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        Mail::assertSent(WalletTransactionPinOtp::class, 7);
    }

    public function test_otp_send_limit_is_scoped_to_purpose_as_well_as_user(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();
        $this->actingAs($user, 'web');

        for ($requestNumber = 1; $requestNumber <= 5; $requestNumber++) {
            $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_change'])
                ->assertOk();
        }

        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_change'])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
        $this->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_reset'])
            ->assertOk();
    }

    public function test_otp_delivery_failure_is_reported_and_does_not_leave_an_active_code(): void
    {
        $user = User::factory()->create();
        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Mail transport unavailable.'));

        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet_pin_otp');

        $this->assertDatabaseCount('wallet_pin_otps', 0);
    }

    public function test_pin_must_be_exactly_four_digits_and_leading_zero_pins_are_supported(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_set'])
            ->assertOk();
        $code = $this->latestOtpCode();
        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_set',
            'code' => $code,
        ])->assertOk();

        foreach (['123', '12345', '12A4'] as $invalidPin) {
            $this->postJson(route('wallet-pin.save'), [
                'purpose' => 'wallet_password_set',
                'wallet_transaction_pin' => $invalidPin,
                'wallet_transaction_pin_confirmation' => $invalidPin,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('wallet_pin_otp');
        }

        $this->postJson(route('wallet-pin.save'), [
            'purpose' => 'wallet_password_set',
            'wallet_transaction_pin' => '0000',
            'wallet_transaction_pin_confirmation' => '0000',
        ])->assertOk()->assertJsonPath('message', 'Wallet Transaction PIN set successfully.');

        $user->refresh();
        $this->assertTrue(Hash::check('0000', $user->wallet_transaction_password_hash));
        $this->assertNotSame('0000', $user->wallet_transaction_password_hash);
        app(WalletTransactionPinService::class)->assertValid($user, '0000', '127.0.0.1');
    }

    public function test_sell_and_withdrawal_cannot_be_submitted_without_a_valid_pin(): void
    {
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '500.00000000']);
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')
            ->post(route('exchange.requests.quote'), [
                'submission_key' => $submissionKey,
                'amount' => '25',
            ])->assertOk();
        preg_match('/name="quote_token" value="([^"]+)"/', $quote->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->post(route('exchange.requests.store'), [
            'submission_key' => $submissionKey,
            'quote_token' => $matches[1],
            'amount' => '25',
            'wallet_transaction_pin' => '1234',
        ])->assertUnprocessable()
            ->assertSee('Incorrect Wallet Transaction PIN.');
        $this->assertDatabaseCount('exchange_requests', 0);

        $this->post(route('wallet.withdrawals.store'), [
            'submission_key' => (string) Str::uuid(),
            'amount' => '10.00',
            'payout_method' => 'cash',
            'wallet_transaction_pin' => '1234',
        ])->assertSessionHasErrors('wallet_transaction_pin');
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_sell_and_withdrawal_reject_pin_values_that_are_not_exactly_four_digits(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seed(ExchangeRateSeeder::class);
        $customer = User::factory()->create(['balance' => '500.00000000']);
        $submissionKey = (string) Str::uuid();
        $quote = $this->actingAs($customer, 'web')
            ->post(route('exchange.requests.quote'), [
                'submission_key' => $submissionKey,
                'amount' => '25',
            ])->assertOk();
        preg_match('/name="quote_token" value="([^"]+)"/', $quote->getContent(), $matches);

        foreach (['123', '12345', '12A4'] as $invalidPin) {
            $this->post(route('exchange.requests.store'), [
                'submission_key' => $submissionKey,
                'quote_token' => $matches[1],
                'amount' => '25',
                'wallet_transaction_pin' => $invalidPin,
            ])->assertUnprocessable()
                ->assertSee('must be 4 digits');

            $this->from(route('wallet'))->post(route('wallet.withdrawals.store'), [
                'submission_key' => (string) Str::uuid(),
                'amount' => '10.00',
                'payout_method' => 'cash',
                'wallet_transaction_pin' => $invalidPin,
            ])->assertSessionHasErrors('wallet_transaction_pin');
        }

        $this->assertDatabaseCount('exchange_requests', 0);
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_wrong_otp_attempts_consume_the_code_and_pin_attempts_are_rate_limited(): void
    {
        Mail::fake();
        RateLimiter::clear('wallet-pin:ip:'.hash('sha256', '127.0.0.1'));
        $user = User::factory()->create(['balance' => '500.00000000']);
        $user->forceFill(['wallet_transaction_password_hash' => Hash::make('1234')])->save();

        $this->actingAs($user, 'web')
            ->postJson(route('wallet-pin.otp.send'), ['purpose' => 'wallet_password_change'])
            ->assertOk();
        $code = $this->latestOtpCode();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('wallet-pin.otp.verify'), [
                'purpose' => 'wallet_password_change',
                'code' => '000000',
            ])->assertUnprocessable()
                ->assertJsonPath('errors.wallet_pin_otp.0', 'Invalid verification code.');
        }
        $this->postJson(route('wallet-pin.otp.verify'), [
            'purpose' => 'wallet_password_change',
            'code' => $code,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.wallet_pin_otp.0', 'Too many attempts. Please try again later.');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                app(WalletTransactionPinService::class)->assertValid($user, '6543', '127.0.0.1');
                $this->fail('An incorrect PIN was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame('Incorrect Wallet Transaction PIN.', $exception->errors()['wallet_transaction_pin'][0]);
            }
        }
        try {
            app(WalletTransactionPinService::class)->assertValid($user, '1234', '127.0.0.1');
            $this->fail('The PIN lockout did not block another verification.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Too many incorrect Wallet Transaction PIN attempts.',
                $exception->errors()['wallet_transaction_pin'][0],
            );
        }
    }

    private function latestOtpCode(): string
    {
        $code = null;
        Mail::assertSent(WalletTransactionPinOtp::class, function (WalletTransactionPinOtp $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertIsString($code);

        return $code;
    }
}
