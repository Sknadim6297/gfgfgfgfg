<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReplied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_a_ticket_through_the_preserved_support_form(): void
    {
        $response = $this->get(route('contact'));
        $response
            ->assertOk()
            ->assertSee('24/7 Premium Support')
            ->assertSee('Submit Request')
            ->assertDontSee('Select Gender')
            ->assertDontSee('submission is unavailable');
        $this->assertSame(1, substr_count($response->getContent(), '<span>Login</span>'));

        $response = $this->post(route('support.tickets.store'), [
            'name' => 'Guest Customer',
            'email' => 'guest@example.test',
            'subject' => 'Deposit has not arrived',
            'category' => 'Deposit',
            'message' => 'I submitted a deposit but it is not in my wallet yet.',
            'user_id' => 9999,
            'status' => 'closed',
            'priority' => 'high',
        ]);

        $ticket = SupportTicket::query()->with('messages')->firstOrFail();
        $response->assertRedirect(route('contact'));
        $this->assertMatchesRegularExpression('/^QPM-TKT-[A-Z0-9]{6}$/', $ticket->ticket_number);
        $this->assertNull($ticket->user_id);
        $this->assertSame('Guest Customer', $ticket->guest_name);
        $this->assertSame('guest@example.test', $ticket->guest_email);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('normal', $ticket->priority);
        $this->assertSame('guest', $ticket->messages->first()->sender_type);
        $this->assertDatabaseCount('support_ticket_messages', 1);
    }

    public function test_customer_ticket_is_owned_and_only_its_customer_can_read_or_reply(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();

        $this->actingAs($customer, 'web')
            ->post(route('support.tickets.store'), [
                'subject' => 'Exchange question',
                'category' => 'Exchange',
                'message' => 'I need help with an exchange request.',
                'user_id' => $otherCustomer->id,
                'status' => 'closed',
                'priority' => 'high',
            ])
            ->assertRedirect(route('contact'));

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame($customer->id, $ticket->user_id);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('normal', $ticket->priority);
        $this->assertNull($ticket->guest_email);

        auth('web')->logout();
        $this->get(route('support.tickets.show', $ticket->ticket_number))
            ->assertRedirect(route('login'));

        $this->actingAs($customer, 'web');
        $this->get(route('support.tickets.show', $ticket->ticket_number))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('I need help with an exchange request.')
            ->assertSee('wallet-header-link', false)
            ->assertDontSee('login-btn', false);

        auth('web')->logout();
        $this->actingAs($otherCustomer, 'web')
            ->get(route('support.tickets.show', $ticket->ticket_number))
            ->assertNotFound();
    }

    public function test_customer_and_admin_can_reply_and_customer_receives_clickable_database_notification(): void
    {
        $customer = User::factory()->create();
        $admin = User::factory()->create([
            'role' => 'admin',
            'totp_enabled' => true,
        ]);
        $ticket = $this->ticketFor($customer);

        $this->actingAs($customer, 'web')
            ->post(route('support.tickets.reply', $ticket->ticket_number), [
                'message' => 'Here is the additional information you requested.',
                'status' => 'closed',
                'priority' => 'high',
            ])
            ->assertRedirect(route('support.tickets.show', $ticket->ticket_number));
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'customer',
            'sender_user_id' => $customer->id,
            'message' => 'Here is the additional information you requested.',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_verified' => true])
            ->post(route('admin.support-tickets.reply', $ticket->ticket_number), [
                'message' => 'We have reviewed your request and resolved it.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'admin',
            'sender_user_id' => $admin->id,
            'message' => 'We have reviewed your request and resolved it.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $customer->id,
            'type' => SupportTicketReplied::class,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'admin.support_ticket_replied',
        ]);

        auth('admin')->logout();
        $this->actingAs($customer, 'web')
            ->get(route('contact'))
            ->assertOk()
            ->assertSee('Support replied to ticket '.$ticket->ticket_number)
            ->assertSee(route('support.tickets.show', $ticket->ticket_number));
    }

    public function test_admin_can_search_filter_update_status_and_priority_while_admin_2fa_is_enforced(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'totp_enabled' => true,
        ]);
        $customer = User::factory()->create();
        $ticket = $this->ticketFor($customer);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.support-tickets.index'))
            ->assertRedirect(route('admin.2fa.challenge'));

        $this->withSession(['admin_2fa_verified' => true])
            ->get(route('admin.support-tickets.index', [
                'search' => $ticket->ticket_number,
                'status' => 'open',
                'category' => 'Deposit',
                'priority' => 'normal',
            ]))
            ->assertOk()
            ->assertSee($ticket->ticket_number);

        $this->put(route('admin.support-tickets.update', $ticket->ticket_number), [
            'status' => 'in_progress',
            'priority' => 'high',
        ])->assertRedirect();

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertSame('high', $ticket->fresh()->priority);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.support_ticket_status_changed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.support_ticket_priority_changed']);
        $this->get(route('admin.support-tickets.show', $ticket->ticket_number))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('Ticket history');
    }

    public function test_closed_ticket_rejects_customer_replies_and_message_html_is_escaped(): void
    {
        $customer = User::factory()->create();
        $ticket = $this->ticketFor($customer, ['status' => 'closed']);
        $message = '<script>alert("xss")</script>';
        DB::table('support_ticket_messages')
            ->where('support_ticket_id', $ticket->id)
            ->update(['message' => $message]);

        $this->actingAs($customer, 'web')
            ->post(route('support.tickets.reply', $ticket->ticket_number), [
                'message' => '<script>alert("xss")</script>',
            ])
            ->assertSessionHasErrors('message');

        $this->get(route('support.tickets.show', $ticket->ticket_number))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false)
            ->assertDontSee($message, false);
        $this->assertDatabaseCount('support_ticket_messages', 1);
    }

    private function ticketFor(User $customer, array $attributes = []): SupportTicket
    {
        $ticket = SupportTicket::create([
            'ticket_number' => 'QPM-TKT-'.strtoupper(fake()->unique()->bothify('??????')),
            'user_id' => $customer->id,
            'subject' => 'Deposit support request',
            'category' => 'Deposit',
            'status' => 'open',
            'priority' => 'normal',
            ...$attributes,
        ]);

        $ticket->messages()->create([
            'sender_type' => 'customer',
            'sender_user_id' => $customer->id,
            'message' => 'I need help with a pending deposit.',
        ]);

        return $ticket;
    }
}
