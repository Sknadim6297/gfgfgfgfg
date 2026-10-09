<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketReplied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
        ]);
        $tickets = SupportTicket::query()
            ->with('user:id,name,email,customer_id')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('ticket_number', 'like', '%'.$search.'%')
                        ->orWhere('subject', 'like', '%'.$search.'%')
                        ->orWhere('guest_name', 'like', '%'.$search.'%')
                        ->orWhere('guest_email', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($validated['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.support.index', [
            'tickets' => $tickets,
            'categories' => SupportTicket::CATEGORIES,
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        $ticket->load(['user', 'messages' => fn ($query) => $query->oldest()]);

        return view('admin.support.show', [
            'ticket' => $ticket,
            'audit' => AuditLog::query()
                ->where('auditable_type', SupportTicket::class)
                ->where('auditable_id', $ticket->id)
                ->with('actor')
                ->latest()
                ->get(),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $ticket, $validated, $admin): void {
            $lockedTicket = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $message = $lockedTicket->messages()->create([
                'sender_type' => 'admin',
                'sender_user_id' => $admin->id,
                'message' => trim($validated['message']),
            ]);
            $lockedTicket->touch();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $lockedTicket->user_id,
                'event' => 'admin.support_ticket_replied',
                'auditable_type' => SupportTicket::class,
                'auditable_id' => $lockedTicket->id,
                'metadata' => [
                    'ticket_number' => $lockedTicket->ticket_number,
                    'message_id' => $message->id,
                ],
                'ip_address' => $request->ip(),
            ]);

            $lockedTicket->user?->notify(new SupportTicketReplied($lockedTicket));
        });

        return back()->with('status', 'Support reply sent.');
    }

    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $ticket, $validated, $admin): void {
            $lockedTicket = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $before = [
                'status' => $lockedTicket->status,
                'priority' => $lockedTicket->priority,
            ];
            $lockedTicket->forceFill($validated)->save();

            if ($before['status'] !== $lockedTicket->status) {
                $this->audit($request, $admin->id, $lockedTicket, 'admin.support_ticket_status_changed', [
                    'before' => $before['status'],
                    'after' => $lockedTicket->status,
                ]);
                if ($lockedTicket->status === 'closed') {
                    $this->audit($request, $admin->id, $lockedTicket, 'admin.support_ticket_closed', [
                        'previous_status' => $before['status'],
                    ]);
                }
            }
            if ($before['priority'] !== $lockedTicket->priority) {
                $this->audit($request, $admin->id, $lockedTicket, 'admin.support_ticket_priority_changed', [
                    'before' => $before['priority'],
                    'after' => $lockedTicket->priority,
                ]);
            }
        });

        return back()->with('status', 'Support ticket updated.');
    }

    private function audit(Request $request, int $adminId, SupportTicket $ticket, string $event, array $metadata): void
    {
        AuditLog::create([
            'actor_user_id' => $adminId,
            'subject_user_id' => $ticket->user_id,
            'event' => $event,
            'auditable_type' => SupportTicket::class,
            'auditable_id' => $ticket->id,
            'metadata' => ['ticket_number' => $ticket->ticket_number, ...$metadata],
            'ip_address' => $request->ip(),
        ]);
    }
}
