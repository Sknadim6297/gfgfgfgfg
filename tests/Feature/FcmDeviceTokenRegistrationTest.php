<?php

namespace Tests\Feature;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FcmDeviceTokenRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authenticated_customers_can_register_or_unregister_tokens(): void
    {
        $this->postJson(route('profile.notifications.device-tokens.store'), ['token' => 'fcm-token-anonymous'])
            ->assertUnauthorized();
        $this->deleteJson(route('profile.notifications.device-tokens.destroy'), ['token' => 'fcm-token-anonymous'])
            ->assertUnauthorized();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), ['token' => 'fcm-token-admin'])
            ->assertForbidden();
    }

    public function test_customer_can_register_the_same_token_idempotently_without_exposing_it(): void
    {
        $user = User::factory()->create();
        $token = 'fcm-token-register-repeat';
        $this->actingAs($user, 'web');

        $this->postJson(route('profile.notifications.device-tokens.store'), [
            'token' => $token,
            'device_label' => 'Mobile browser',
        ])->assertOk()
            ->assertExactJson(['status' => 'registered'])
            ->assertDontSee($token);

        $this->postJson(route('profile.notifications.device-tokens.store'), [
            'token' => $token,
            'device_label' => 'Mobile browser',
        ])->assertOk()
            ->assertExactJson(['status' => 'registered'])
            ->assertDontSee($token);

        $this->assertSame(1, $user->fcmDeviceTokens()->count());
        $this->assertSame('Mobile browser', $user->fcmDeviceTokens()->firstOrFail()->device_label);
    }

    public function test_token_registration_does_not_require_server_side_firebase_credentials(): void
    {
        config([
            'services.firebase.credentials_path' => null,
            'services.firebase.project_id' => null,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), [
                'token' => 'fcm-token-registration-without-service-account',
            ])
            ->assertOk()
            ->assertExactJson(['status' => 'registered']);

        $this->assertSame(1, $user->fcmDeviceTokens()->count());
    }

    public function test_profile_push_urls_stay_on_the_current_origin_when_app_url_is_localhost(): void
    {
        config(['app.url' => 'http://localhost']);
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get('/profile')
            ->assertOk()
            ->assertSee('data-register-url="/profile/notifications/device-tokens"', false)
            ->assertSee('data-unregister-url="/profile/notifications/device-tokens"', false)
            ->assertSee('data-service-worker-url="/firebase-messaging/sw.js"', false)
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false)
            ->assertSee('apple-mobile-web-app-capable', false);
    }

    public function test_customer_cannot_claim_a_token_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $token = 'fcm-token-owned-by-first-user';
        $deviceToken = $owner->fcmDeviceTokens()->create(['token' => $token]);

        $this->actingAs($intruder, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), ['token' => $token])
            ->assertStatus(409)
            ->assertDontSee($token);

        $this->assertSame($owner->id, $deviceToken->fresh()->user_id);
        $this->assertSame(0, $intruder->fcmDeviceTokens()->count());
    }

    public function test_invalid_registration_input_is_rejected_without_storing_a_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), [
                'token' => '',
                'device_label' => str_repeat('x', 121),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'device_label']);

        $this->assertSame(0, $user->fcmDeviceTokens()->count());
    }

    public function test_token_rotation_removes_only_the_current_users_previous_token(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $oldToken = 'fcm-token-before-rotation';
        $foreignToken = 'fcm-token-foreign-device';
        $user->fcmDeviceTokens()->create(['token' => $oldToken]);
        $otherUser->fcmDeviceTokens()->create(['token' => $foreignToken]);

        $this->actingAs($user, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), [
                'token' => 'fcm-token-after-rotation',
                'previous_token' => $oldToken,
            ])
            ->assertOk();

        $this->postJson(route('profile.notifications.device-tokens.store'), [
            'token' => 'fcm-token-other-rotation',
            'previous_token' => $foreignToken,
        ])->assertOk();

        $this->assertDatabaseMissing('fcm_device_tokens', [
            'token_hash' => FcmDeviceToken::hashToken($oldToken),
        ]);
        $this->assertDatabaseHas('fcm_device_tokens', [
            'user_id' => $otherUser->id,
            'token_hash' => FcmDeviceToken::hashToken($foreignToken),
        ]);
    }

    public function test_unregister_removes_only_the_authenticated_users_matching_token(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = 'fcm-token-to-unregister';
        $foreignToken = 'fcm-token-to-keep';
        $user->fcmDeviceTokens()->create(['token' => $token]);
        $otherUser->fcmDeviceTokens()->create(['token' => $foreignToken]);

        $this->actingAs($user, 'web')
            ->deleteJson(route('profile.notifications.device-tokens.destroy'), ['token' => $token])
            ->assertOk()
            ->assertExactJson(['status' => 'unregistered'])
            ->assertDontSee($token);

        $this->deleteJson(route('profile.notifications.device-tokens.destroy'), ['token' => $foreignToken])
            ->assertOk();

        $this->assertSame(0, $user->fcmDeviceTokens()->count());
        $this->assertSame(1, $otherUser->fcmDeviceTokens()->count());
    }

    public function test_register_and_unregister_validation_do_not_echo_token_values(): void
    {
        $user = User::factory()->create();
        $token = 'secret-fcm-token-validation';

        $this->actingAs($user, 'web')
            ->postJson(route('profile.notifications.device-tokens.store'), [
                'token' => $token,
                'device_label' => str_repeat('x', 121),
            ])
            ->assertUnprocessable()
            ->assertDontSee($token);

        $this->deleteJson(route('profile.notifications.device-tokens.destroy'), ['token' => ''])
            ->assertUnprocessable()
            ->assertDontSee($token);
        $this->assertSame(0, DB::table('fcm_device_tokens')->count());
    }
}
