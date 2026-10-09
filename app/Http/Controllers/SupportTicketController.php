<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketReplied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $authenticatedUser = $request->user('web');
        $customer = $authenticatedUser?->role === 'user' ? $authenticatedUser : null;
        $tickets = $customer
            ? $customer->supportTickets()->latest('updated_at')->paginate(8)->withQueryString()
            : null;
        $replyNotifications = $customer
            ? $customer->unreadNotifications()
                ->where('type', SupportTicketReplied::class)
                ->latest()
                ->limit(10)
                ->get()
            : collect();

        return view('pages.contact', [
            'customer' => $customer,
            'tickets' => $tickets,
            'categories' => SupportTicket::CATEGORIES,
            'replyNotifications' => $replyNotifications,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $authenticatedUser = $request->user('web');
        $customer = $authenticatedUser?->role === 'user' ? $authenticatedUser : null;
        $validated = $request->validate([
            'name' => [$customer ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$customer ? 'nullable' : 'required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $ticket = DB::transaction(function () use ($request, $customer, $validated): SupportTicket {
            $ticket = SupportTicket::create([
                'ticket_number' => $this->newTicketNumber(),
                'user_id' => $customer?->id,
                'guest_name' => $customer ? null : trim($validated['name']),
                'guest_email' => $customer ? null : strtolower(trim($validated['email'])),
                'subject' => trim($validated['subject']),
                'category' => $validated['category'],
                'status' => 'open',
                'priority' => 'normal',
            ]);

            $ticket->messages()->create([
                'sender_type' => $customer ? 'customer' : 'guest',
                'sender_user_id' => $customer?->id,
                'message' => trim($validated['message']),
            ]);

            AuditLog::create([
                'actor_user_id' => $customer?->id,
                'subject_user_id' => $customer?->id,
                'event' => 'support.ticket_created',
                'auditable_type' => SupportTicket::class,
                'auditable_id' => $ticket->id,
                'metadata' => ['ticket_number' => $ticket->ticket_number],
                'ip_address' => $request->ip(),
            ]);

            return $ticket;
        });

        return redirect()->route('contact')
            ->with('status', 'Support request '.$ticket->ticket_number.' was submitted.');
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        $customer = $request->user('web');
        abort_unless($customer?->role === 'user' && $ticket->user_id === $customer->id, 404);

        $ticket->load(['messages' => fn ($query) => $query->oldest()]);
        $customer->unreadNotifications()
            ->where('type', SupportTicketReplied::class)
            ->get()
            ->each(function ($notification) use ($ticket): void {
                if (($notification->data['ticket_number'] ?? null) === $ticket->ticket_number) {
                    $notification->markAsRead();
                }
            });

        return view('pages.support-ticket', [
            'ticket' => $ticket,
            'customer' => $customer,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $customer = $request->user('web');
        abort_unless($customer?->role === 'user' && $ticket->user_id === $customer->id, 404);
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        DB::transaction(function () use ($ticket, $customer, $validated): void {
            $lockedTicket = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedTicket->user_id !== $customer->id, 404);
            if ($lockedTicket->status === 'closed') {
                throw ValidationException::withMessages([
                    'message' => 'This ticket is closed and cannot accept replies.',
                ]);
            }

            $lockedTicket->messages()->create([
                'sender_type' => 'customer',
                'sender_user_id' => $customer->id,
                'message' => trim($validated['message']),
            ]);
            $lockedTicket->touch();
        });

        return redirect()->route('support.tickets.show', $ticket->ticket_number)
            ->with('status', 'Your reply was sent.');
    }

    private function newTicketNumber(): string
    {
        do {
            $ticketNumber = 'QPM-TKT-'.Str::upper(Str::random(6));
        } while (SupportTicket::query()->where('ticket_number', $ticketNumber)->exists());

        return $ticketNumber;
    }
}
