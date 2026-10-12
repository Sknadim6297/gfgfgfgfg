<?php

namespace Tests\Feature;

use App\Jobs\BuildFcmNotificationCampaign;
use App\Jobs\FanoutFcmNotificationCampaign;
use App\Jobs\SendFcmNotificationBatch;
use App\Models\FcmDeviceToken;
use App\Models\FcmNotificationCampaign;
use App\Models\FcmNotificationDelivery;
use App\Models\User;
use App\Services\FcmHttpV1Client;
use App\Services\FirebaseAccessTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_requires_admin_login_and_two_factor(): void
    {
        $this->get(route('admin.push-notifications.index'))
            ->assertRedirect(route('admin.login'));

        $admin = $this->admin(['totp_enabled' => false]);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.push-notifications.index'))
            ->assertRedirect(route('admin.2fa.setup'));
    }

    public function test_authorized_admin_can_view_form_and_campaign_history(): void
    {
        $admin = $this->admin();
        $campaign = $this->campaign($admin);
        $this->subscribedCustomer('history-token');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.push-notifications.index'))
            ->assertOk()
            ->assertSee('Push notifications')
            ->assertSee('Queue notification')
            ->assertSee('Notification title')
            ->assertSee('Notification message')
            ->assertSee('name="_token"', false)
            ->assertSee('Test announcement')
            ->assertSee('Firebase accepted the request; it does not confirm a device displayed it.');
    }

    public function test_admin_campaign_summary_uses_all_time_database_statuses(): void
    {
        $admin = $this->admin();
        $this->campaign($admin, ['status' => 'queued']);
        $this->campaign($admin, ['status' => 'processing']);
        $this->campaign($admin, ['status' => 'completed']);
        $this->campaign($admin, ['status' => 'completed_with_failures']);
        $this->campaign($admin, ['status' => 'failed']);

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.push-notifications.index'))
            ->assertOk()
            ->assertSee('All-time campaign counts; the campaign history pagination does not affect these totals.')
            ->assertSee('Total campaigns')
            ->assertSee('Queued / processing')
            ->assertSee('2')
            ->assertSee('Completed')
            ->assertSee('Failed');
    }

    public function test_empty_subscriber_list_explains_disabled_queue_button(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.push-notifications.index'))
            ->assertOk()
            ->assertSee('0 subscribed customers')
            ->assertSee('No active customers have subscribed to push notifications yet.')
            ->assertSee('At least one active customer with an enabled device is required.')
            ->assertSee('Queue notification')
            ->assertSee('disabled', false);
    }

    public function test_all_subscribers_submission_is_rejected_when_there_are_no_subscribers(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.push-notifications.index'))
            ->post(route('admin.push-notifications.store'), [
                'title' => 'Service update',
                'message' => 'A generic service update.',
                'audience' => 'all_subscribed',
            ])
            ->assertRedirect(route('admin.push-notifications.index'))
            ->assertSessionHasErrors('audience');

        $this->assertDatabaseCount('fcm_notification_campaigns', 0);
        $this->assertDatabaseCount('fcm_notification_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_specific_customer_validation_error_keeps_selector_visible(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->subscribedCustomer('specific-selector-token');
        $unsubscribedCustomer = User::factory()->create();

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.push-notifications.index'))
            ->post(route('admin.push-notifications.store'), [
                'title' => 'Service update',
                'message' => 'A generic service update.',
                'audience' => 'specific_user',
                'target_user_id' => $unsubscribedCustomer->id,
            ])
            ->assertRedirect(route('admin.push-notifications.index'))
            ->assertSessionHasErrors('target_user_id');

        $this->get(route('admin.push-notifications.index'))
            ->assertOk()
            ->assertSee('data-push-target-field')
            ->assertDontSee('data-push-target-field hidden', false)
            ->assertSee('Select a subscribed customer')
            ->assertSee('Select an active customer with a subscribed device.');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fcm_notification_campaigns', 0);
    }

    public function test_admin_campaign_submission_is_validated_and_queued_on_database_connection(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $customer = $this->subscribedCustomer('validation-token');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.push-notifications.store'), [
                'title' => 'Service update',
                'message' => 'A generic service update.',
                'audience' => 'specific_user',
                'target_user_id' => $customer->id,
                'destination_url' => 'https://evil.example/phish',
            ])
            ->assertSessionHasErrors('destination_url');
        $this->assertSame(0, FcmNotificationCampaign::count());

        $this->post(route('admin.push-notifications.store'), [
            'title' => 'Service update',
            'message' => 'A generic service update.',
            'audience' => 'specific_user',
            'target_user_id' => $customer->id,
            'destination_url' => '/profile',
        ])->assertRedirect(route('admin.push-notifications.index'))
            ->assertSessionHas('status');

        Queue::assertPushed(BuildFcmNotificationCampaign::class, fn (BuildFcmNotificationCampaign $job): bool => $job->connection === 'database');
        $campaign = FcmNotificationCampaign::firstOrFail();
        $this->assertSame($customer->id, $campaign->target_user_id);
        $this->assertSame('queued', $campaign->status);
        $this->assertSame('/profile', $campaign->destination_url);
    }

    public function test_customer_and_admin_cannot_submit_campaigns(): void
    {
        Queue::fake();
        $this->post(route('admin.push-notifications.store'), [])
            ->assertRedirect(route('admin.login'));

        $customer = User::factory()->create();
        $this->actingAs($customer, 'web')
            ->post(route('admin.push-notifications.store'), [])
            ->assertRedirect(route('admin.login'));

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.push-notifications.store'), [
                'title' => ['invalid'],
                'message' => ['invalid'],
                'audience' => 'unknown',
                'destination_url' => ['invalid'],
            ])
            ->assertSessionHasErrors(['title', 'message', 'audience', 'destination_url']);

        Queue::assertNothingPushed();
        $this->assertSame(0, FcmNotificationCampaign::count());
    }

    public function test_specific_user_campaign_snapshots_only_that_customers_devices(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $customer = $this->subscribedCustomer('target-token-one');
        $customer->fcmDeviceTokens()->create(['token' => 'target-token-two']);
        $otherCustomer = $this->subscribedCustomer('other-customer-token');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.push-notifications.store'), [
                'title' => 'Service update',
                'message' => 'A generic service update.',
                'audience' => 'specific_user',
                'target_user_id' => $customer->id,
            ])->assertRedirect();

        $campaign = FcmNotificationCampaign::firstOrFail();
        (new BuildFcmNotificationCampaign($campaign->id))->handle();

        $deliveries = $campaign->deliveries()->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame(
            [$customer->id],
            $deliveries->map(fn (FcmNotificationDelivery $delivery): int => $delivery->deviceToken->user_id)->unique()->values()->all(),
        );
        $this->assertDatabaseMissing('fcm_notification_deliveries', [
            'campaign_id' => $campaign->id,
            'fcm_device_token_id' => $otherCustomer->fcmDeviceTokens()->firstOrFail()->id,
        ]);
        Queue::assertPushed(FanoutFcmNotificationCampaign::class);
    }

    public function test_all_subscribed_campaign_targets_customer_devices_but_not_admins_or_inactive_accounts(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $customer = $this->subscribedCustomer('all-token-one');
        $customer->fcmDeviceTokens()->create(['token' => 'all-token-two']);
        $this->subscribedCustomer('all-token-three');
        $inactive = $this->subscribedCustomer('inactive-token', ['account_status' => 'suspended']);
        $admin->fcmDeviceTokens()->create(['token' => 'admin-token']);

        $campaign = $this->campaign($admin, [
            'audience' => 'all_subscribed',
            'status' => 'queued',
            'recipient_count' => 0,
        ]);
        (new BuildFcmNotificationCampaign($campaign->id))->handle();

        $this->assertSame(3, $campaign->fresh()->recipient_count);
        $this->assertSame(3, $campaign->deliveries()->count());
        $this->assertDatabaseMissing('fcm_notification_deliveries', [
            'campaign_id' => $campaign->id,
            'fcm_device_token_id' => $inactive->fcmDeviceTokens()->firstOrFail()->id,
        ]);
        $this->assertDatabaseMissing('fcm_notification_deliveries', [
            'campaign_id' => $campaign->id,
            'fcm_device_token_id' => $admin->fcmDeviceTokens()->firstOrFail()->id,
        ]);
    }

    public function test_campaign_that_loses_all_subscribers_before_build_is_marked_failed_without_deliveries(): void
    {
        Queue::fake();
        $campaign = $this->campaign($this->admin(), [
            'audience' => 'all_subscribed',
            'status' => 'queued',
            'recipient_count' => 0,
        ]);

        (new BuildFcmNotificationCampaign($campaign->id))->handle();

        $this->assertSame('failed', $campaign->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->recipient_count);
        $this->assertNotNull($campaign->fresh()->completed_at);
        $this->assertDatabaseCount('fcm_notification_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_fcm_success_is_recorded_as_accepted_not_delivered(): void
    {
        config(['app.url' => 'https://quickpay.example.test']);
        $this->fakeFcmAccessToken();
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/*/messages:send' => Http::response([
                'name' => 'projects/notification-web-46cdf/messages/message-123',
            ], 200),
        ]);

        $campaign = $this->campaign($this->admin(), [
            'title' => 'Campaign title from admin',
            'message' => 'Campaign message from admin',
        ]);
        $device = $this->subscribedCustomer('accepted-test-token')->fcmDeviceTokens()->firstOrFail();
        $delivery = $this->delivery($campaign, $device);

        (new SendFcmNotificationBatch($campaign->id, [$delivery->id]))->handle(app(FcmHttpV1Client::class));

        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame('projects/notification-web-46cdf/messages/message-123', $delivery->fresh()->fcm_message_name);
        $this->assertNotNull($delivery->fresh()->accepted_at);
        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->accepted_count);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'fcm.googleapis.com')
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && ! isset($request['message']['notification'])
            && $request['message']['webpush']['notification']['title'] === 'Campaign title from admin'
            && $request['message']['webpush']['notification']['body'] === 'Campaign message from admin'
            && $request['message']['webpush']['notification']['icon'] === 'https://quickpay.example.test/icons/icon-192.png'
            && $request['message']['webpush']['notification']['tag'] === 'quickpay-push-'.$campaign->id
            && ! isset($request['message']['webpush']['fcm_options'])
            && $request['message']['data']['title'] === 'Campaign title from admin'
            && $request['message']['data']['body'] === 'Campaign message from admin'
            && $request['message']['data']['url'] === 'https://quickpay.example.test/profile'
            && $request['message']['data']['icon'] === 'https://quickpay.example.test/icons/icon-192.png'
            && $request['message']['data']['tag'] === 'quickpay-push-'.$campaign->id);
    }

    public function test_duplicate_delivery_job_does_not_send_an_already_accepted_delivery_again(): void
    {
        $this->fakeFcmAccessToken();
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/*/messages:send' => Http::response([
                'name' => 'projects/notification-web-46cdf/messages/idempotent-message',
            ], 200),
        ]);

        $campaign = $this->campaign($this->admin());
        $device = $this->subscribedCustomer('idempotent-test-token')->fcmDeviceTokens()->firstOrFail();
        $delivery = $this->delivery($campaign, $device);
        $job = new SendFcmNotificationBatch($campaign->id, [$delivery->id]);

        $job->handle(app(FcmHttpV1Client::class));
        $job->handle(app(FcmHttpV1Client::class));

        Http::assertSentCount(1);
        $this->assertSame('accepted', $delivery->fresh()->status);
    }

    public function test_unregistered_fcm_token_is_failed_and_removed_without_leaking_token(): void
    {
        $this->fakeFcmAccessToken();
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/*/messages:send' => Http::response([
                'error' => [
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ], 404),
        ]);

        $campaign = $this->campaign($this->admin());
        $device = $this->subscribedCustomer('unregistered-secret-token')->fcmDeviceTokens()->firstOrFail();
        $delivery = $this->delivery($campaign, $device);

        (new SendFcmNotificationBatch($campaign->id, [$delivery->id]))->handle(app(FcmHttpV1Client::class));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('UNREGISTERED', $delivery->fresh()->error_code);
        $this->assertNull($delivery->fresh()->fcm_device_token_id);
        $this->assertDatabaseMissing('fcm_device_tokens', ['id' => $device->id]);
        $this->assertSame('completed_with_failures', $campaign->fresh()->status);
        Http::assertSent(fn ($request): bool => $request['message']['token'] === 'unregistered-secret-token');
    }

    public function test_transient_fcm_failure_requeues_delivery_and_exposes_no_response_body(): void
    {
        $this->fakeFcmAccessToken();
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/*/messages:send' => Http::response([
                'error' => ['message' => 'secret-token-and-private-response-must-not-be-exposed'],
            ], 429),
        ]);

        $campaign = $this->campaign($this->admin());
        $device = $this->subscribedCustomer('transient-token')->fcmDeviceTokens()->firstOrFail();
        $delivery = $this->delivery($campaign, $device);

        try {
            (new SendFcmNotificationBatch($campaign->id, [$delivery->id]))->handle(app(FcmHttpV1Client::class));
            $this->fail('Expected transient failure to be retried by Laravel.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary Firebase delivery failure.', $exception->getMessage());
            $this->assertStringNotContainsString('secret-token', $exception->getMessage());
        }

        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_missing_service_account_configuration_fails_with_a_sanitized_message(): void
    {
        config([
            'services.firebase.credentials_path' => 'sensitive-private-path',
            'services.firebase.project_id' => null,
        ]);

        try {
            app(FirebaseAccessTokenProvider::class)->accessToken();
            $this->fail('Expected missing Firebase configuration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Firebase server credentials are not configured.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-private-path', $exception->getMessage());
        }
    }

    private function fakeFcmAccessToken(): void
    {
        $provider = Mockery::mock(FirebaseAccessTokenProvider::class);
        $provider->shouldReceive('accessToken')->andReturn('test-access-token');
        $this->app->instance(FirebaseAccessTokenProvider::class, $provider);
        config(['services.firebase.project_id' => 'notification-web-46cdf']);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
            ...$attributes,
        ]);
    }

    private function subscribedCustomer(string $token, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->fcmDeviceTokens()->create(['token' => $token]);

        return $user;
    }

    private function campaign(User $admin, array $attributes = []): FcmNotificationCampaign
    {
        return FcmNotificationCampaign::create([
            'admin_id' => $admin->id,
            'audience' => 'specific_user',
            'target_user_id' => null,
            'title' => 'Test announcement',
            'message' => 'A generic test announcement.',
            'status' => 'processing',
            'recipient_count' => 1,
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    private function delivery(FcmNotificationCampaign $campaign, FcmDeviceToken $device): FcmNotificationDelivery
    {
        return $campaign->deliveries()->create([
            'fcm_device_token_id' => $device->id,
            'token_hash' => $device->token_hash,
            'status' => 'pending',
        ]);
    }
}
