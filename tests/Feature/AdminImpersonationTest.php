<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_active_member_dashboard_read_only_without_changing_its_ui(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create([
            'name' => 'Impersonated Member',
            'balance' => '12.34000000',
            'inr_balance' => '567.89',
        ]);
        $passwordHash = $member->password;

        $this->adminSession($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Login as User')
            ->assertSee('target="_blank" rel="noopener"', false)
            ->assertSee(route('admin.users.impersonate', $member), false);

        $this->post(route('admin.users.impersonate', $member))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertSame($admin->id, session('admin_impersonation.admin_id'));
        $this->assertSame($member->id, session('admin_impersonation.user_id'));

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('wallet'), false)
            ->assertDontSee('Admin Viewing')
            ->assertDontSee('admin-impersonation')
            ->assertDontSee('Return to Admin Panel');

        $this->put(route('profile.update'), [
            'name' => 'Changed by impersonator',
            'email' => 'changed@example.test',
        ])->assertForbidden();
        $this->post(route('wallet.withdrawals.store'), [])->assertForbidden();
        $this->post(route('deposit.store'), [])->assertForbidden();
        $this->post(route('support.tickets.store'), [])->assertForbidden();

        $this->assertSame('Impersonated Member', $member->fresh()->name);
        $this->assertSame($passwordHash, $member->fresh()->password);
        $this->assertDatabaseCount('withdrawal_requests', 0);
        $this->assertDatabaseCount('deposits', 0);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'event' => 'admin.user_impersonation_started',
        ]);

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('End impersonation and return to Admin Panel');
        $this->post(route('admin.impersonation.stop'))
            ->assertRedirect(route('admin.users.index'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->assertFalse(session()->has('admin_impersonation'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'event' => 'admin.user_impersonation_ended',
        ]);
    }

    public function test_impersonation_requires_two_factor_admin_and_active_member_accounts(): void
    {
        $admin = $this->admin();
        $suspendedMember = User::factory()->create(['account_status' => 'suspended']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->adminSession($admin)
            ->post(route('admin.users.impersonate', $suspendedMember))
            ->assertNotFound();
        $this->post(route('admin.users.impersonate', $otherAdmin))
            ->assertNotFound();

        $this->assertGuest('web');
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'admin.user_impersonation_started',
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

    private function adminSession(User $admin): static
    {
        return $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true]);
    }
}
