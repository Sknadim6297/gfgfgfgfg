<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\ExchangeRequest;
use App\Models\User;
use App\Support\DemoPlatformActivity;
use App\Support\Totp;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AuthenticationAndPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_logs_in_immediately_without_email_verification(): void
    {
        $response = $this->withSession(['url.intended' => route('dashboard')])->post(route('register.store'), [
            'name' => 'Alice Example',
            'email' => 'ALICE@example.test',
            'mobile' => '9876543210',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
            'terms' => '1',
        ]);

        $user = User::where('email', 'alice@example.test')->firstOrFail();
        $response->assertRedirect(route('home'));
        $this->assertSame('user', $user->role);
        $this->assertSame('active', $user->account_status);
        $this->assertMatchesRegularExpression('/^ALIC[0-9]{8}$/', $user->customer_id);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{12}$/', $user->referral_code);
        $this->assertNull($user->referred_by_user_id);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('StrongPassword!123', $user->password));
        $this->assertAuthenticatedAs($user, 'web');
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Your account has been created successfully.');
        $this->get(route('dashboard'))->assertRedirect(route('home'));
        $this->assertFalse(Route::has('verification.notice'));
    }

    public function test_customer_ids_are_unique_immutable_and_not_mass_assignable(): void
    {
        $first = User::factory()->create(['name' => 'Sanjoy Maity']);
        $second = User::factory()->create(['name' => 'Sanjoy Maity']);
        $attemptedId = 'SKNAABCDEF';
        $submitted = new User([
            'name' => 'Submitted ID',
            'email' => 'submitted-id@example.test',
            'password' => 'password',
            'customer_id' => $attemptedId,
        ]);
        $submitted->save();

        $this->assertMatchesRegularExpression('/^SANJ[0-9]{8}$/', $first->customer_id);
        $this->assertMatchesRegularExpression('/^SANJ[0-9]{8}$/', $second->customer_id);
        $this->assertNotSame($first->customer_id, $second->customer_id);
        $this->assertNotSame($attemptedId, $submitted->customer_id);
        $this->assertSame(12, strlen($first->customer_id));
        $this->assertTrue(collect(Schema::getIndexes('users'))->contains(
            fn (array $index): bool => $index['columns'] === ['customer_id'] && $index['unique']
        ));
        $originalId = $first->customer_id;

        try {
            $first->customer_id = 'SANJ00000000';
            $first->save();
            $this->fail('Customer ID was changed after account creation.');
        } catch (\LogicException $exception) {
            $this->assertSame('A customer ID cannot be changed after account creation.', $exception->getMessage());
        }
        $this->assertSame($originalId, $first->fresh()->customer_id);
    }

    public function test_customer_id_migration_backfills_existing_accounts(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        DB::table('users')->where('id', $first->id)->update(['customer_id' => 'QPMABCDEFGH']);
        DB::table('users')->where('id', $second->id)->update(['customer_id' => null]);
        $third = User::factory()->create(['customer_id' => null]);
        DB::table('users')->where('id', $third->id)->update(['customer_id' => 'SKNA258021']);
        $validId = 'SKNA258021';
        $migration = require database_path('migrations/2026_10_07_120000_reformat_public_customer_ids.php');
        $migration->up();

        $firstId = $first->fresh()->customer_id;
        $secondId = $second->fresh()->customer_id;
        $this->assertMatchesRegularExpression('/^SKNA[0-9]{6}$/', $firstId);
        $this->assertMatchesRegularExpression('/^SKNA[0-9]{6}$/', $secondId);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame($validId, $third->fresh()->customer_id);
    }

    public function test_customer_id_prefix_uses_first_four_ascii_letters_of_name(): void
    {
        $examples = [
            'Sanjoy Maity' => 'SANJ',
            'SK Nadim' => 'SKNA',
            'Rahul Das' => 'RAHU',
            'A-1 l!ce' => 'ALCE',
        ];

        foreach ($examples as $name => $expectedPrefix) {
            $user = User::factory()->create(['name' => $name]);
            $this->assertMatchesRegularExpression('/^'.$expectedPrefix.'[0-9]{8}$/', $user->customer_id);
        }
    }

    public function test_short_and_nonletter_names_use_consistent_four_letter_prefixes(): void
    {
        $shortName = User::factory()->create(['name' => 'Li']);
        $nameWithoutLetters = User::factory()->create(['name' => '123 !!!']);

        $this->assertMatchesRegularExpression('/^LIXX[0-9]{8}$/', $shortName->customer_id);
        $this->assertMatchesRegularExpression('/^USER[0-9]{8}$/', $nameWithoutLetters->customer_id);
    }

    public function test_creating_new_customer_does_not_change_existing_customer_id(): void
    {
        $existing = User::factory()->create(['name' => 'Sanjoy Maity']);
        DB::table('users')->where('id', $existing->id)->update(['customer_id' => 'SKNA258021']);
        $existingId = $existing->fresh()->customer_id;

        User::factory()->create(['name' => 'Rahul Das']);

        $this->assertSame('SKNA258021', $existingId);
        $this->assertSame($existingId, $existing->fresh()->customer_id);
    }

    public function test_registration_associates_a_valid_customer_referrer_and_rejects_non_customer_codes(): void
    {
        $referrer = User::factory()->create();
        $this->get(route('register', ['ref' => $referrer->referral_code]))
            ->assertOk()
            ->assertSee('name="referral_code"', false)
            ->assertSee('value="'.$referrer->referral_code.'"', false);

        $response = $this->post(route('register.store'), [
            'name' => 'Referred Customer',
            'email' => 'referred@example.test',
            'referral_code' => strtolower($referrer->referral_code),
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
            'terms' => '1',
        ]);
        $response->assertRedirect(route('home'));
        $referred = User::where('email', 'referred@example.test')->firstOrFail();
        $this->assertSame($referrer->id, $referred->referred_by_user_id);
        $this->assertNotSame($referrer->referral_code, $referred->referral_code);

        Auth::guard('web')->logout();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Invalid Referrer Customer',
            'email' => 'invalid-referrer@example.test',
            'referral_code' => $admin->referral_code,
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
            'terms' => '1',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors('referral_code');
        $this->assertDatabaseMissing('users', ['email' => 'invalid-referrer@example.test']);
    }

    public function test_user_login_redirects_home_and_suspended_account_is_denied(): void
    {
        $user = User::factory()->unverified()->create()->fresh();
        $this->withSession(['url.intended' => route('dashboard')])->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user, 'web');
        $this->get(route('login'))->assertRedirect(route('home'));

        Auth::guard('web')->logout();
        $user->forceFill(['account_status' => 'suspended'])->save();
        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_unverified_customer_can_access_permitted_customer_pages(): void
    {
        $user = User::factory()->unverified()->create()->fresh();
        $this->assertNull($user->email_verified_at);

        $this->actingAs($user, 'web');
        foreach ([
            route('profile'),
            route('wallet'),
            route('exchange.sell'),
            route('deposit.create'),
            route('profile.referrals'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_dashboard_redirects_home_and_profile_is_a_separate_page(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()])->fresh();
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        ExchangeRequest::create([
            'user_id' => $otherUser->id,
            'usdt_amount' => '98765.43210000',
            'exchange_rate' => '91.00000000',
            'inr_amount' => '8987654.32',
            'status' => 'pending',
        ]);

        $this->actingAs($user, 'web')->get(route('dashboard'))
            ->assertRedirect(route('home'));

        $this->actingAs($user, 'web')->get(route('profile'))
            ->assertOk()
            ->assertSee('aria-label="Log out"', false)
            ->assertSee('Account Balance')
            ->assertSee('Total Reward')
            ->assertSee('ID: <strong>'.$user->customer_id.'</strong>', false)
            ->assertDontSee('ID: <strong>'.$user->id.'</strong>', false)
            ->assertDontSee('no reward ledger')
            ->assertSee('Enter Bank Details')
            ->assertSee('Sell Now')
            ->assertSee('Bank Details')
            ->assertSee('Exchange History')
            ->assertSee('Sell requests and their current status')
            ->assertSee('Referrals History')
            ->assertSee('Reset Password')
            ->assertSee('Not Added')
            ->assertDontSee('Deposit History')
            ->assertDontSee('98765.43210000');
        $this->actingAs($user, 'web')->get(route('exchange'))
            ->assertOk()
            ->assertDontSee('aria-label="Sign out"', false)
            ->assertDontSee('header-logout-form');
        $this->assertMatchesRegularExpression('/^[A-Z]{4}[0-9]{8}$/', $user->customer_id);

        $this->actingAs($user, 'web')->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Bank Details')
            ->assertDontSee('Current Password')
            ->assertDontSee('USDT Wallet Details')
            ->assertDontSee('name="usdt_wallet_address"', false)
            ->assertSee('content-area app-shell profile-dashboard', false)
            ->assertSee('bottom-nav app-shell', false);

        $this->actingAs($user, 'web')->get(route('profile.password'))
            ->assertOk()
            ->assertSee('Current Password')
            ->assertSee('New Password');

        $unverified = User::factory()->unverified()->create()->fresh();
        $this->actingAs($unverified, 'web')->get(route('profile'))
            ->assertOk();
    }

    public function test_profile_details_and_password_can_be_updated_from_profile_page(): void
    {
        $user = User::factory()->create(['password' => 'CurrentStrong!Password123']);
        $otherUser = User::factory()->create(['name' => 'Unchanged User']);

        $this->actingAs($user, 'web')
            ->from(route('profile'))
            ->put(route('profile.update'), [
                'section' => 'personal',
                'name' => 'Updated Profile Name',
                'mobile' => '9876543210',
                'gender' => 'Other',
                'email' => 'changed@example.test',
                'user_id' => $otherUser->id,
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHas('status', 'Profile details updated.');

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('"notice":{"type":"success","message":"Profile details updated."}', false)
            ->assertDontSee('<div class="portal-status" role="status">Profile details updated.</div>', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Profile Name',
            'mobile' => '9876543210',
            'gender' => 'Other',
            'email' => $user->email,
        ]);
        $this->assertSame('Unchanged User', $otherUser->fresh()->name);

        $this->from(route('profile'))->put(route('password.change'), [
            'current_password' => 'CurrentStrong!Password123',
            'password' => 'ReplacementStrong!Password456',
            'password_confirmation' => 'ReplacementStrong!Password456',
        ])->assertRedirect(route('profile.password'))
            ->assertSessionHas('status', 'Password changed.');

        $this->assertTrue(Hash::check('ReplacementStrong!Password456', $user->fresh()->password));
    }

    public function test_profile_validation_errors_are_available_to_the_shared_popup_without_inline_flash_notice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->from(route('profile'))
            ->put(route('profile.update'), [
                'section' => 'personal',
                'name' => '',
                'mobile' => '',
                'gender' => '',
            ])
            ->assertRedirect(route('profile'))
            ->assertSessionHasErrors('name');

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('quickpay-notifications')
            ->assertSee('The name field is required.')
            ->assertDontSee('<div class="portal-status" role="status">', false);
    }

    public function test_profile_bank_details_update_without_password_and_wallet_data_remains_encrypted(): void
    {
        $user = User::factory()->create(['password' => 'CurrentStrong!Password123']);
        $otherUser = User::factory()->create([
            'account_holder_name' => 'Other Customer',
            'bank_name' => 'Other Bank',
            'account_number' => '998877665544',
            'ifsc_code' => 'WXYZ0123456',
            'branch_name' => 'Other Branch',
            'account_type' => 'Current',
        ]);

        $this->actingAs($user, 'web')
            ->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('Bank Details')
            ->assertSee('name="account_number"', false)
            ->assertSee('Account Holder Name')
            ->assertSee('Bank Name')
            ->assertSee('IFSC Code')
            ->assertSee('Branch Name')
            ->assertSee('Account Type')
            ->assertSee('Save Bank Details')
            ->assertDontSee('Current Password')
            ->assertDontSee('Changes require your current password.')
            ->assertDontSee('USDT Wallet Details')
            ->assertDontSee('Wallet Address')
            ->assertDontSee('Re-authentication required');

        $this->actingAs($user, 'web')->get(route('profile'))
            ->assertOk()
            ->assertSee('Not Added')
            ->assertDontSee('998877665544')
            ->assertDontSee('WXYZ0123456');

        $bankDetails = [
            'section' => 'bank',
            'account_holder_name' => 'Morgan Example',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
            'ifsc_code' => 'ABCD0123456',
            'branch_name' => 'Central Branch',
            'account_type' => 'Savings',
            'user_id' => $otherUser->id,
        ];

        $this->from(route('profile.bank'))
            ->put(route('profile.update'), [
                ...$bankDetails,
                'ifsc_code' => 'invalid',
                'account_type' => 'Business',
            ])
            ->assertRedirect(route('profile.bank'))
            ->assertSessionHasErrors(['ifsc_code', 'account_type'])
            ->assertSessionMissing('_old_input.account_number');
        $this->assertNull($user->fresh()->account_number);

        $this->from(route('profile.bank'))
            ->put(route('profile.update'), [
                ...$bankDetails,
            ])
            ->assertRedirect(route('profile.bank'))
            ->assertSessionHas('status', 'Bank details submitted for verification.');

        $user->refresh();
        $this->assertSame('Morgan Example', $user->account_holder_name);
        $this->assertSame('Example Bank', $user->bank_name);
        $this->assertSame('123456789012', $user->account_number);
        $this->assertSame('ABCD0123456', $user->ifsc_code);
        $this->assertSame('Central Branch', $user->branch_name);
        $this->assertSame('Savings', $user->account_type);
        $this->assertSame('Other Customer', $otherUser->fresh()->account_holder_name);
        $this->assertSame('998877665544', $otherUser->fresh()->account_number);
        $this->assertStringNotContainsString('123456789012', $user->getRawOriginal('account_number'));
        $this->assertDatabaseHas('audit_logs', [
            'subject_user_id' => $user->id,
            'event' => 'user.bank_details_submitted',
        ]);
        $this->assertStringNotContainsString(
            '123456789012',
            json_encode(AuditLog::where('event', 'user.bank_details_submitted')->firstOrFail()->metadata)
        );

        $walletDetails = [
            'section' => 'wallet',
            'wallet_current_password' => 'CurrentStrong!Password123',
            'usdt_wallet_address' => 'TQn9Y2khDD95J42FQtQTdwV',
        ];

        $this->from(route('profile.bank'))
            ->put(route('profile.update'), $walletDetails)
            ->assertRedirect(route('profile.bank'))
            ->assertSessionHas('status', 'USDT wallet details updated.');

        $user->refresh();
        $this->assertSame($walletDetails['usdt_wallet_address'], $user->usdt_wallet_address);
        $this->assertStringNotContainsString($walletDetails['usdt_wallet_address'], $user->getRawOriginal('usdt_wallet_address'));

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Pending')
            ->assertDontSee('123456789012')
            ->assertDontSee('ABCD0123456');

        $this->get(route('profile.bank'))
            ->assertOk()
            ->assertSee('••••••9012')
            ->assertSee('Pending Verification')
            ->assertSee('value="Morgan Example"', false)
            ->assertSee('value="Example Bank"', false)
            ->assertDontSee('USDT Wallet Details')
            ->assertDontSee('TQn9Y2khDD95J42FQtQTdwV');
    }

    public function test_profile_exchange_history_is_customer_scoped_and_uses_stored_snapshots(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownRequest = ExchangeRequest::create([
            'user_id' => $user->id,
            'request_reference' => 'OWN-REFERENCE',
            'usdt_amount' => '5.12345678',
            'exchange_rate' => '101.25000000',
            'inr_amount' => '518.75',
            'status' => 'completed',
            'transaction_reference' => 'OWN-REFERENCE',
        ]);
        $otherRequest = ExchangeRequest::create([
            'user_id' => $otherUser->id,
            'request_reference' => 'OTHER-PRIVATE-REFERENCE',
            'usdt_amount' => '98765.43210000',
            'exchange_rate' => '91.00000000',
            'inr_amount' => '8987654.32',
            'status' => 'pending',
            'transaction_reference' => 'OTHER-PRIVATE-REFERENCE',
        ]);

        $this->actingAs($user, 'web')->get(route('profile.exchanges'))
            ->assertOk()
            ->assertSee('OWN-REFERENCE')
            ->assertSee('5.12345678 USDT')
            ->assertSee('₹101.25')
            ->assertDontSee('OTHER-PRIVATE-REFERENCE')
            ->assertDontSee('98765.43210000');

        $this->get(route('profile.exchanges.show', $ownRequest))
            ->assertOk()
            ->assertSee('518.75')
            ->assertSee('historical values recorded');
        $this->get(route('profile.exchanges.show', $otherRequest))
            ->assertNotFound();
    }

    public function test_exchange_history_empty_states_offer_sell_or_clear_filter_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get(route('profile.exchanges'))
            ->assertOk()
            ->assertSee('No exchange requests yet')
            ->assertSee('Sell USDT')
            ->assertSee(route('exchange.sell'));

        $this->get(route('profile.exchanges', ['search' => 'missing-reference']))
            ->assertOk()
            ->assertSee('No matching exchange requests')
            ->assertSee('Clear filters')
            ->assertDontSee('Sell USDT');
    }

    public function test_referrals_pages_show_real_counts_and_privacy_safe_history(): void
    {
        $referrer = User::factory()->create(['name' => 'Referral Owner']);
        $referred = User::factory()->create([
            'name' => 'Private Customer Name',
            'referred_by_user_id' => $referrer->id,
            'account_status' => 'active',
        ]);

        $this->actingAs($referrer, 'web')->get(route('profile.referrals'))
            ->assertOk()
            ->assertSee('Referral dashboard')
            ->assertSee('Track your invitations, qualification progress, and credited rewards.')
            ->assertSee($referrer->referral_code)
            ->assertSee(route('register', ['ref' => $referrer->referral_code]))
            ->assertSee('Total referrals')
            ->assertSee('Commission history')
            ->assertDontSee('Total Direct Referrals')
            ->assertDontSee('Qualified Referrals');

        $this->get(route('profile.referrals.history'))
            ->assertOk()
            ->assertSee('Customer '.substr($referred->customer_id, 0, 2).str_repeat('•', max(4, strlen($referred->customer_id) - 4)).substr($referred->customer_id, -2))
            ->assertSee('Qualification progress')
            ->assertSee('Private Customer Name')
            ->assertDontSee($referred->email);
    }

    public function test_referral_assignment_cannot_be_changed_after_registration(): void
    {
        $firstReferrer = User::factory()->create();
        $secondReferrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $firstReferrer->id]);

        try {
            $referred->referred_by_user_id = $secondReferrer->id;
            $referred->save();
            $this->fail('A referral assignment was changed after registration.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'A customer referral assignment cannot be changed after registration.',
                $exception->getMessage()
            );
        }
    }

    public function test_profile_routes_require_a_customer_session_and_reject_admin_users(): void
    {
        $this->get(route('profile'))->assertRedirect(route('login'));
        $this->get(route('profile.bank'))->assertRedirect(route('login'));
        $this->put(route('profile.update'), [
            'section' => 'bank',
            'account_holder_name' => 'Unauthenticated Update',
        ])->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->get(route('profile'))->assertForbidden();
        $this->get(route('profile.referrals.history'))->assertForbidden();
        $this->put(route('profile.update'), [
            'section' => 'personal',
            'name' => 'Changed Admin',
        ])->assertForbidden();
        $this->put(route('password.change'), [
            'current_password' => 'password',
            'password' => 'ReplacementStrong!Password456',
            'password_confirmation' => 'ReplacementStrong!Password456',
        ])->assertForbidden();
    }

    public function test_customer_logout_uses_post_and_is_not_exposed_as_a_get_route(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get(route('logout'))
            ->assertMethodNotAllowed();
        $this->post(route('logout'))
            ->assertRedirect(route('landing'));
        $this->assertGuest('web');
    }

    public function test_home_header_has_guest_controls_and_authenticated_support_wallet_icons(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(route('login'))
            ->assertSee(route('register'))
            ->assertSee('LIVE EXCHANGE PLATFORM')
            ->assertDontSee('data-user-menu-toggle');
        $this->get(route('home'))->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('register'));

        $user = User::factory()->create(['name' => 'Morgan Example']);
        $this->actingAs($user, 'web')->get(route('home'))
            ->assertOk()
            ->assertDontSee('Account menu for Morgan Example')
            ->assertDontSee('data-user-menu-toggle')
            ->assertDontSee('bi-person-circle')
            ->assertSee('aria-label="My Wallet"', false)
            ->assertSee(route('wallet'))
            ->assertSee('aria-label="Contact support"', false)
            ->assertSee(route('contact'))
            ->assertSee(route('profile'));
    }

    public function test_homepage_discloses_date_generated_demo_activity_and_conversion_rows(): void
    {
        $user = User::factory()->create(['balance' => '0.00000000']);
        $demo = DemoPlatformActivity::forDate();
        $response = $this->actingAs($user, 'web')->get(route('home'))
            ->assertOk()
            ->assertSee('LIVE RATE')
            ->assertSee('1 USDT =')
            ->assertSee('>100</span>', false)
            ->assertSee('Fast &amp; secure USDT', false)
            ->assertSee('Exchange Now')
            ->assertSee('DEMO PLATFORM ACTIVITY')
            ->assertSee(number_format($demo['active_clients']))
            ->assertSee($demo['deposit_display'])
            ->assertSee($demo['inr_display'])
            ->assertSee('USDT')
            ->assertSee('Best Rate')
            ->assertSee('Freeze-Free Transactions')
            ->assertSee('Trusted Banking Network');

        $this->assertStringNotContainsString('No exchange activity is available', $response->getContent());
        $this->assertStringNotContainsString('Exchange service unavailable', $response->getContent());
        $this->assertSame(9, substr_count($response->getContent(), 'class="usd">↓ $'));
    }

    public function test_homepage_demo_activity_is_independent_of_real_customer_balances_and_sell_records(): void
    {
        $demo = DemoPlatformActivity::forDate();
        $customer = User::factory()->create([
            'balance' => '987654321.00000000',
            'inr_balance' => '1234567890.00',
        ]);
        ExchangeRequest::create([
            'user_id' => $customer->id,
            'request_reference' => 'QPMSELLREALRECORD',
            'rate_plan_name' => 'Actual customer rate',
            'usdt_amount' => '87654321.00000000',
            'exchange_rate' => '110.00000000',
            'inr_amount' => '9641975310.00',
            'available_usd_before' => '87654321.00000000',
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($customer, 'web')
            ->get(route('home'))
            ->assertOk()
            ->assertSee($demo['deposit_display'])
            ->assertSee($demo['inr_display'])
            ->assertDontSee('$87,654,321')
            ->assertDontSee('₹9,641,975,310');
    }

    public function test_homepage_formats_live_rate_for_display_without_changing_stored_precision(): void
    {
        $user = User::factory()->create(['balance' => '0.00000000']);
        $rate = ExchangeRate::where('plan_key', 'base')->firstOrFail();
        $rate->update(['rate' => '100.00000000']);

        $this->actingAs($user, 'web')->get(route('home'))
            ->assertOk()
            ->assertSee('>100</span>', false)
            ->assertSee('family=Poppins:wght@400;500;600;700');

        $this->assertSame(100.0, (float) $rate->fresh()->rate);

        $rate->update(['rate' => '100.50000000']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('>100.5</span>', false);

        $this->assertSame(100.5, (float) $rate->fresh()->rate);
    }

    public function test_exchange_page_restores_demo_rates_and_sell_action_opens_the_sell_flow(): void
    {
        $response = $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('Your Trusted USDT Exchange Platform')
            ->assertSee('100% Safe')
            ->assertSee('Fast Transactions')
            ->assertSee('Global Access')
            ->assertSee('BALANCE')
            ->assertSee('Sign in to view')
            ->assertSee('Add Funds')
            ->assertSee('Sell USDT')
            ->assertSee('Get Your Funds')
            ->assertSee('Earn Together')
            ->assertSee('Live Rates')
            ->assertSee('₹100')
            ->assertSee('10,000 – 19,999.99999999 USDT')
            ->assertSee('₹115')
            ->assertSee('20,000+ USDT')
            ->assertSee('₹120')
            ->assertSee('Trade ')
            ->assertSee('Reliable');

        $this->assertSame(1, substr_count($response->getContent(), 'aria-disabled="true"'));
        $this->assertStringContainsString('href="'.route('login').'"', $response->getContent());
        $this->assertStringContainsString('href="'.route('deposit.create').'"', $response->getContent());
        $this->assertStringNotContainsString('Not available yet', $response->getContent());
        $this->assertStringNotContainsString('No settlement available', $response->getContent());
        $this->assertStringNotContainsString('Rates unavailable', $response->getContent());
    }

    public function test_guest_home_shows_landing_site_with_named_auth_links(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('LIVE EXCHANGE PLATFORM')
            ->assertSee('Convert Your')
            ->assertSee('USDT')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee(asset('assets/landing/css/landing.css'))
            ->assertSee(asset('assets/landing/js/landing.js'))
            ->assertDontSee('LIVE PLATFORM STATS');
    }

    public function test_authenticated_customer_can_open_withdrawal_from_home_action(): void
    {
        $user = User::factory()->create(['balance' => '0.00000000']);
        $this->actingAs($user, 'web');

        $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('href="'.route('wallet.withdrawals.create').'"', false);

        $this->get(route('wallet.withdrawals.create'))
            ->assertOk()
            ->assertSee('Withdraw INR');
    }

    public function test_exchange_page_formats_every_rate_card_and_uses_visible_prime_icon(): void
    {
        $rate = ExchangeRate::where('plan_key', 'base')->firstOrFail();
        $rate->update(['rate' => '100.00000000']);

        $response = $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('1 USDT = 100 INR')
            ->assertSee('₹100')
            ->assertSee('1 USDT = 115 INR')
            ->assertSee('₹115')
            ->assertSee('1 USDT = 120 INR')
            ->assertSee('₹120')
            ->assertSee('bi-diamond-fill')
            ->assertSee('bi-gem');

        $this->assertStringNotContainsString('100.00000000', $response->getContent());
        $this->assertSame(100.0, (float) $rate->fresh()->rate);

        $rate->update(['rate' => '100.50000000']);

        $this->get(route('exchange'))
            ->assertOk()
            ->assertSee('1 USDT = 100.5 INR')
            ->assertSee('₹100.5');

        $this->assertSame(100.5, (float) $rate->fresh()->rate);
    }

    public function test_user_logout_returns_home_with_shared_notification_feedback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->post(route('logout'))
            ->assertRedirect(route('landing'))
            ->assertSessionHas('status', 'Signed out successfully.');

        $this->assertGuest('web');
        $this->get(route('landing'))
            ->assertSee('LIVE EXCHANGE PLATFORM')
            ->assertSee('Signed out successfully.');
    }

    public function test_admin_login_redirects_to_admin_dashboard_not_stale_user_intended_url(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ]);

        $this->withSession(['url.intended' => route('dashboard')])
            ->post(route('admin.login.store'), [
                'email' => $admin->email,
                'password' => 'password',
            ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subHours(2)]);

        $this->post(route('password.update'), [
            'email' => $user->email,
            'token' => $token,
            'password' => 'AnotherStrong!Password123',
            'password_confirmation' => 'AnotherStrong!Password123',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('AnotherStrong!Password123', $user->fresh()->password));
    }

    public function test_admin_password_reset_uses_admin_flow_and_audits_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = Password::createToken($admin);

        $this->post(route('admin.password.update'), [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'NewAdmin!Password123',
            'password_confirmation' => 'NewAdmin!Password123',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('NewAdmin!Password123', $admin->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.password_reset']);
    }

    public function test_admin_seeder_creates_a_verified_active_admin_from_environment_password(): void
    {
        config(['admin.password' => 'SeederStrong!Password123']);

        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'admin@quickpaymoney.com')->firstOrFail();
        $this->assertSame('Quick PayMoney Admin', $admin->name);
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->account_status);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertFalse($admin->totp_enabled);
        $this->assertTrue(Hash::check('SeederStrong!Password123', $admin->password));
        $this->assertSame(url('/admin/dashboard'), route('admin.dashboard'));

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'SeederStrong!Password123',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_seeder_does_not_promote_an_existing_customer(): void
    {
        User::factory()->create(['email' => 'admin@quickpaymoney.com']);
        config(['admin.password' => 'SeederStrong!Password123']);

        try {
            (new AdminSeeder)->run();
            $this->fail('The admin seeder must not promote a customer account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing customer account', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['email' => 'admin@quickpaymoney.com', 'role' => 'user']);
    }

    public function test_admin_seeder_does_not_overwrite_an_existing_admin(): void
    {
        $admin = User::factory()->create([
            'name' => 'Existing Admin',
            'email' => 'admin@quickpaymoney.com',
            'role' => 'admin',
            'account_status' => 'suspended',
            'email_verified_at' => null,
            'password' => 'Existing!Password123',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ]);
        $originalPasswordHash = $admin->password;
        config([
            'admin.password' => 'SeederStrong!Password456',
            'admin.reset_password' => false,
        ]);

        $this->seed(AdminSeeder::class);

        $admin->refresh();
        $this->assertSame('Existing Admin', $admin->name);
        $this->assertSame('suspended', $admin->account_status);
        $this->assertNull($admin->email_verified_at);
        $this->assertSame($originalPasswordHash, $admin->password);
        $this->assertTrue(Hash::check('Existing!Password123', $admin->password));
        $this->assertTrue($admin->totp_enabled);
        $this->assertNotNull($admin->totp_secret);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_seeder_resets_existing_admin_password_only_when_explicitly_enabled(): void
    {
        $totpSecret = Totp::generateSecret();
        $admin = User::factory()->create([
            'name' => 'Existing Admin',
            'email' => 'admin@quickpaymoney.com',
            'role' => 'admin',
            'account_status' => 'active',
            'password' => 'Existing!Password123',
            'totp_enabled' => true,
            'totp_secret' => $totpSecret,
            'totp_last_counter' => 42,
        ]);
        config([
            'admin.password' => 'SeederStrong!Password456',
            'admin.reset_password' => true,
        ]);

        $this->seed(AdminSeeder::class);

        $admin->refresh();
        $this->assertTrue(Hash::check('SeederStrong!Password456', $admin->password));
        $this->assertFalse(Hash::check('Existing!Password123', $admin->password));
        $this->assertSame('Existing Admin', $admin->name);
        $this->assertSame('active', $admin->account_status);
        $this->assertTrue($admin->totp_enabled);
        $this->assertSame($totpSecret, $admin->totp_secret);
        $this->assertSame(42, $admin->totp_last_counter);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_seeder_requires_environment_credentials(): void
    {
        config(['admin.password' => null]);

        try {
            (new AdminSeeder)->run();
            $this->fail('The admin seeder must reject missing credentials.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_seeder_refuses_non_mysql_databases_outside_tests(): void
    {
        config([
            'app.env' => 'local',
            'admin.password' => 'SeederStrong!Password123',
        ]);

        try {
            (new AdminSeeder)->run();
            $this->fail('The admin seeder must require MySQL outside automated tests.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB_CONNECTION=mysql', $exception->getMessage());
        } finally {
            config(['app.env' => 'testing']);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_guard_provider_only_retrieves_admin_accounts(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = Auth::guard('admin')->getProvider();

        $this->assertNull($provider->retrieveById($customer->id));
        $this->assertSame($admin->id, $provider->retrieveById($admin->id)->id);
    }

    public function test_shared_alerts_render_validation_errors_and_escape_flash_content(): void
    {
        $this->from(route('admin.login'))->post(route('admin.login.store'), [
            'email' => 'missing@example.test',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('quickpay-notifications')
            ->assertSee('These credentials do not match our records.');

        $this->withSession(['status' => '<img src=x onerror=alert(1)>'])
            ->get(route('admin.login'))
            ->assertOk()
            ->assertSee('\\u003Cimg src=x onerror=alert(1)\\u003E', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_customer_and_admin_credentials_are_isolated_between_login_pages(): void
    {
        $customer = User::factory()->create([
            'email' => 'customer@example.test',
            'password' => 'CustomerStrong!Password123',
        ]);
        $admin = User::factory()->create([
            'email' => 'admin@example.test',
            'email_verified_at' => null,
            'role' => 'admin',
            'password' => 'AdminStrong!Password123',
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $customer->email,
            'password' => 'CustomerStrong!Password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'AdminStrong!Password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'AdminStrong!Password123',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');

        Auth::guard('admin')->logout();
        $this->actingAs($customer, 'admin')->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_login_rate_limit_is_keyed_to_email_and_ip(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Please wait a moment');
    }

    public function test_totp_matches_rfc6238_sha1_vector(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame(1, Totp::matchingCounter($secret, '287082', 59));
        $this->assertTrue(Totp::verify($secret, '287082', 59));
        $this->assertFalse(Totp::verify($secret, '000000', 59));
    }

    public function test_admin_login_opens_the_dashboard_without_a_verification_code(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
            'account_status' => 'active',
            'password' => 'AdminStrong!Password123',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ]);

        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'AdminStrong!Password123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Administration overview')
            ->assertDontSee('Two-factor verification')
            ->assertDontSee('Local development demo code');
        $this->get('/admin/two-factor/challenge')->assertNotFound();
        $this->get(route('admin.login'))->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.login.store'), [
            'email' => $admin->email,
            'password' => 'AdminStrong!Password123',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_guest_admin_dashboard_redirects_to_admin_login(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
        $this->get('/admin')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_manage_users_and_exchange_statuses_with_audit(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ])->fresh();
        $user = User::factory()->create(['email_verified_at' => now(), 'balance' => '25.00']);
        $exchange = ExchangeRequest::create([
            'user_id' => $user->id,
            'usdt_amount' => '25.00000000',
            'exchange_rate' => '90.00000000',
            'inr_amount' => '2250.00',
            'status' => 'pending',
        ]);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);
        $this->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertDontSee('Email verified')
            ->assertDontSee('<dt>Verification status</dt><dd><span class="portal-badge', false)
            ->assertSee('Bank details')
            ->assertSee('Wallet Transaction PIN')
            ->assertSee('data-confirm="The selected role and account status will take effect immediately."', false);
        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Bank')
            ->assertSee('Account status')
            ->assertDontSee('>Verification</th>', false);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Pending verifications');

        $this->put(route('admin.users.update', $user), [
            'account_status' => 'suspended',
            'role' => 'user',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'account_status' => 'suspended']);

        $this->put(route('admin.exchanges.update', $exchange), [
            'status' => 'completed',
            'transaction_reference' => 'TOO-EARLY-001',
            'admin_notes' => 'Invalid transition should not persist.',
        ])->assertStatus(422);
        $this->assertDatabaseHas('exchange_requests', ['id' => $exchange->id, 'status' => 'pending']);

        $this->put(route('admin.exchanges.update', $exchange), [
            'status' => 'processing',
            'transaction_reference' => '',
            'admin_notes' => 'Reviewing manually',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('exchange_requests', ['id' => $exchange->id, 'status' => 'processing']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_status_updated']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);

        $this->put(route('admin.exchanges.update', $exchange), [
            'status' => 'completed',
            'transaction_reference' => 'MANUAL-REF-001',
            'admin_notes' => 'USD debited and INR credited from the saved rate snapshot.',
        ])->assertSessionHas('status');
        $this->assertDatabaseHas('exchange_requests', ['id' => $exchange->id, 'status' => 'completed']);
        $this->assertSame('0.00000000', $user->fresh()->balance);
        $this->assertSame('2250.00', $user->fresh()->inr_balance);
    }

    public function test_admin_financial_and_user_summaries_keep_wallet_currencies_separate_and_unfiltered(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
            'balance' => '999.00000000',
            'inr_balance' => '999.00',
        ]);
        User::factory()->create(['balance' => '1.12345678', 'inr_balance' => '2.34']);
        User::factory()->create(['balance' => '2.00000000', 'inr_balance' => '3.66']);
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->get(route('admin.users.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertSee('All-time totals across customer accounts; directory filters only affect the list below.')
            ->assertSee('$3.12')
            ->assertSee('₹6.00')
            ->assertDontSee('$1,002.12');

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('All-time recorded amounts. USD, USDT, and INR are separate measures and are not combined.')
            ->assertSee('0 USDT')
            ->assertSee('₹0.00 INR at saved request rates')
            ->assertSee('Excludes DEMO wallet credits')
            ->assertSee('₹0.00')
            ->assertSee('$3.12')
            ->assertSee('₹6.00');
    }

    public function test_every_admin_management_page_uses_the_branded_admin_shell(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ])->fresh();
        $user = User::factory()->create();
        $exchange = ExchangeRequest::create([
            'user_id' => $user->id,
            'usdt_amount' => '25.00000000',
            'exchange_rate' => '90.00000000',
            'inr_amount' => '2250.00',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        foreach ([
            route('admin.dashboard'),
            route('admin.users.index'),
            route('admin.users.show', $user),
            route('admin.exchanges.index'),
            route('admin.exchanges.show', $exchange),
            route('admin.rates.edit'),
            route('admin.profile'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('admin-sidebar')
                ->assertSee('admin-topbar')
                ->assertSee('Quick PayMoney')
                ->assertSee(route('admin.users.index'))
                ->assertSee(route('admin.exchanges.index'))
                ->assertSee(route('admin.rates.edit'))
                ->assertSee(route('admin.profile'));
        }
    }

    public function test_non_admin_cannot_access_admin_management(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'admin')->get(route('admin.users.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_rate_update_stores_exact_decimal_and_audits_change(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'totp_enabled' => true,
            'totp_secret' => Totp::generateSecret(),
        ])->fresh();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true]);

        $this->put(route('admin.rates.update'), ['rate' => '90.12345678'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('exchange_rates', ['pair' => 'USDT_INR', 'rate' => '90.12345678']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.exchange_rate_updated']);
        $this->get(route('admin.rates.edit'))->assertOk()->assertSee('Recent rate changes')->assertSee('90.12345678');
        $customer = User::factory()->create(['balance' => '0.00000000']);
        $this->actingAs($customer, 'web')->get(route('home'))->assertOk()->assertSee('90.12345678');
        $this->get(route('exchange'))->assertOk()->assertSee('90.12345678');
    }
}
