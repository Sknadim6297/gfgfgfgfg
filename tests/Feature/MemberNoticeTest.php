<?php

namespace Tests\Feature;

use App\Models\MemberNotice;
use App\Models\User;
use App\Support\MemberNoticePrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MemberNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_and_deactivate_a_notice(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.notices.store'), [
                'title' => 'Wallet maintenance',
                'description' => 'Deposits stay available during the window.',
                'type' => 'maintenance',
                'status' => 'published',
                'priority' => 'high',
                'popup_enabled' => '1',
            ])
            ->assertRedirect(route('admin.notices.index'))
            ->assertSessionHas('status', 'Notice saved.');

        $notice = MemberNotice::query()->firstOrFail();
        $this->assertSame('Wallet maintenance', $notice->title);
        $this->assertTrue($notice->popup_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.member_notice_created']);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->put(route('admin.notices.update', $notice), [
                'title' => 'Updated maintenance window',
                'description' => 'The window has moved.',
                'type' => 'warning',
                'status' => 'inactive',
                'priority' => 'urgent',
                'popup_enabled' => '0',
            ])
            ->assertRedirect(route('admin.notices.index'));

        $notice->refresh();
        $this->assertSame('Updated maintenance window', $notice->title);
        $this->assertSame('inactive', $notice->status);
        $this->assertFalse($notice->popup_enabled);

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.notices.index'))
            ->patch(route('admin.notices.status', $notice), ['status' => 'published'])
            ->assertRedirect(route('admin.notices.index'))
            ->assertSessionHas('status', 'Notice published.');
        $this->assertSame('published', $notice->fresh()->status);
    }

    public function test_member_login_popup_is_dismissed_for_the_session_and_returns_on_the_next_login(): void
    {
        $notice = $this->notice(['title' => 'Please review the daily rate']);
        $member = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $member->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('home'));

        $firstToken = session(MemberNoticePrompt::SESSION_KEY.'.token');
        $this->assertNotEmpty($firstToken);
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="member-notice-dialog"', false)
            ->assertSee('Please review the daily rate')
            ->assertSee('Got it')
            ->assertSee('member-notices.css', false);
        $this->assertStringContainsString('@media (max-width: 640px)', file_get_contents(public_path('assets/css/member-notices.css')));
        $this->assertStringContainsString('overflow: auto', file_get_contents(public_path('assets/css/member-notices.css')));

        $this->postJson(route('notices.dismiss', $notice))
            ->assertOk()
            ->assertJsonPath('message', 'Notice dismissed for this sign-in.');

        $this->get(route('home'))->assertOk()->assertDontSee('id="member-notice-dialog"', false);
        $this->get(route('profile'))->assertOk()->assertDontSee('id="member-notice-dialog"', false);
        $this->get(route('notices.index'))->assertOk()->assertSee('Please review the daily rate');

        $this->post(route('logout'))->assertRedirect();
        $this->post(route('login.store'), [
            'email' => $member->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertNotSame($firstToken, session(MemberNoticePrompt::SESSION_KEY.'.token'));
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="member-notice-dialog"', false)
            ->assertSee('Please review the daily rate');
    }

    public function test_multiple_notices_are_queued_by_priority_and_dismissed_one_at_a_time(): void
    {
        $normal = $this->notice(['title' => 'Normal update', 'priority' => 'normal']);
        $urgent = $this->notice(['title' => 'Urgent update', 'priority' => 'urgent']);
        $high = $this->notice(['title' => 'High update', 'priority' => 'high']);
        $member = User::factory()->create();

        $this->actingAs($member, 'web')->get(route('home'))->assertOk();
        $queue = $this->popupQueue($this->get(route('home')));
        $this->assertSame(['Urgent update', 'High update', 'Normal update'], array_column($queue, 'title'));

        $this->postJson(route('notices.dismiss', $urgent))->assertOk();
        $this->assertSame(['High update', 'Normal update'], array_column($this->popupQueue($this->get(route('home'))), 'title'));
        $this->postJson(route('notices.dismiss', $high))->assertOk();
        $this->postJson(route('notices.dismiss', $normal))->assertOk();
        $this->get(route('home'))->assertDontSee('id="member-notice-dialog"', false);
        $this->get(route('notices.index'))
            ->assertSee('Urgent update')
            ->assertSee('High update')
            ->assertSee('Normal update');
    }

    public function test_expired_scheduled_draft_and_inactive_notices_stay_private(): void
    {
        $live = $this->notice(['title' => 'Live notice']);
        $this->notice(['title' => 'Expired notice', 'expires_at' => now()->subMinute()]);
        $this->notice(['title' => 'Scheduled notice', 'publish_at' => now()->addDay()]);
        $draft = $this->notice(['title' => 'Draft notice', 'status' => 'draft']);
        $inactive = $this->notice(['title' => 'Inactive notice', 'status' => 'inactive']);
        $historyOnly = $this->notice(['title' => 'History only notice', 'popup_enabled' => false]);
        $member = User::factory()->create();

        $this->actingAs($member, 'web');
        $home = $this->get(route('home'));
        $home->assertSee('Live notice')->assertDontSee('Expired notice')->assertDontSee('Scheduled notice')->assertDontSee('Draft notice')->assertDontSee('Inactive notice')->assertDontSee('History only notice');

        $this->get(route('notices.index'))
            ->assertSee('Live notice')
            ->assertSee('History only notice')
            ->assertDontSee('Expired notice')
            ->assertDontSee('Draft notice')
            ->assertDontSee('Inactive notice');

        $this->get(route('notices.show', $draft))->assertNotFound();
        $this->get(route('notices.show', $inactive))->assertNotFound();
        $this->get(route('notices.show', $live))->assertOk()->assertSee('Live notice');
        $this->postJson(route('notices.dismiss', $draft))->assertNotFound();
    }

    public function test_notice_content_is_escaped_and_unauthorized_users_cannot_manage_notices(): void
    {
        $notice = $this->notice([
            'title' => 'Safe title',
            'description' => '<script>alert(1)</script>',
        ]);
        $member = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($member, 'web')
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);

        $this->get(route('admin.notices.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($member, 'web')->get(route('admin.notices.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($member, 'web')->post(route('admin.notices.store'), [])->assertRedirect(route('admin.login'));
        $this->actingAs($admin, 'admin')->get(route('admin.notices.index'))->assertRedirect(route('admin.2fa.challenge'));
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('id="member-notice-dialog"', false);

        $this->actingAs($member, 'web')->withSession(['admin_impersonation' => ['admin_id' => $admin->id, 'user_id' => $member->id]])
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('id="member-notice-dialog"', false);
    }

    public function test_a_new_authenticated_session_shows_the_popup_again_without_using_browser_storage(): void
    {
        $notice = $this->notice(['title' => 'Session reset notice']);
        $member = User::factory()->create();

        $this->actingAs($member, 'web');
        $this->postJson(route('notices.dismiss', $notice))->assertOk();
        $this->flushSession();
        $this->actingAs($member, 'web');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="member-notice-dialog"', false)
            ->assertSee('Session reset notice')
            ->assertDontSee('localStorage', false);
    }

    public function test_expiry_must_be_after_the_publish_date(): void
    {
        $this->actingAs($this->admin(), 'admin')->withSession(['admin_2fa_verified' => true])
            ->from(route('admin.notices.create'))
            ->post(route('admin.notices.store'), [
                'title' => 'Bad schedule',
                'description' => 'Dates overlap.',
                'type' => 'information',
                'status' => 'draft',
                'priority' => 'normal',
                'publish_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'expires_at' => now()->format('Y-m-d\TH:i'),
                'popup_enabled' => '1',
            ])
            ->assertRedirect(route('admin.notices.create'))
            ->assertSessionHasErrors('expires_at');

        $this->assertDatabaseCount('member_notices', 0);
    }

    private function notice(array $overrides = []): MemberNotice
    {
        return MemberNotice::query()->create(array_merge([
            'title' => 'Member notice',
            'description' => 'A published announcement.',
            'type' => 'information',
            'status' => 'published',
            'priority' => 'normal',
            'popup_enabled' => true,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'totp_enabled' => true,
        ]);
    }

    private function popupQueue(TestResponse $response): array
    {
        $this->assertSame(1, preg_match('/id="member-notice-queue">(.*?)<\/script>/s', $response->getContent(), $matches));

        return json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
