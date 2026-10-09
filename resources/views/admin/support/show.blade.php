@extends('layouts.admin')
@section('title', 'Quick PayMoney | Support Ticket')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">CUSTOMER CARE / TICKET DETAILS</span><h1>{{ $ticket->ticket_number }}</h1><p>{{ $ticket->subject }}</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.support-tickets.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to tickets</a>
    </div>

    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST SUMMARY</span><h2>Ticket details</h2></div><span class="portal-badge {{ $ticket->status }}"><span class="admin-badge-dot"></span>{{ str($ticket->status)->replace('_', ' ')->title() }}</span></div>
            <dl class="admin-detail-list">
                <div><dt>Customer</dt><dd>{{ $ticket->user?->name ?? $ticket->guest_name }}<small>{{ $ticket->user?->email ?? $ticket->guest_email }}</small></dd></div>
                <div><dt>Ticket number</dt><dd>{{ $ticket->ticket_number }}</dd></div>
                <div><dt>Subject</dt><dd>{{ $ticket->subject }}</dd></div>
                <div><dt>Category</dt><dd>{{ $ticket->category }}</dd></div>
                <div><dt>Priority</dt><dd>{{ ucfirst($ticket->priority) }}</dd></div>
                <div><dt>Created</dt><dd>{{ $ticket->created_at->format('M j, Y · H:i') }}</dd></div>
                <div><dt>Last updated</dt><dd>{{ $ticket->updated_at->format('M j, Y · H:i') }}</dd></div>
            </dl>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">WORKFLOW</span><h2>Update ticket</h2><p>Status and priority changes are recorded in the audit history.</p></div></div>
            <form class="admin-form" method="POST" action="{{ route('admin.support-tickets.update', $ticket->ticket_number) }}">
                @csrf
                @method('PUT')
                <label for="status">Status</label>
                <select class="portal-input" id="status" name="status" required>
                    @foreach (\App\Models\SupportTicket::STATUSES as $status)
                        <option value="{{ $status }}" @selected(old('status', $ticket->status) === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                @error('status')<span class="admin-field-error">{{ $message }}</span>@enderror
                <label for="priority">Priority</label>
                <select class="portal-input" id="priority" name="priority" required>
                    @foreach (\App\Models\SupportTicket::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', $ticket->priority) === $priority)>{{ ucfirst($priority) }}</option>
                    @endforeach
                </select>
                @error('priority')<span class="admin-field-error">{{ $message }}</span>@enderror
                <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save ticket</button>
            </form>
        </section>
    </div>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">CONVERSATION</span><h2>Messages</h2></div></div>
        <div class="admin-activity-list">
            @foreach ($ticket->messages as $message)
                <article class="admin-activity-item admin-activity-item-stacked">
                    <span class="admin-activity-icon"><i class="bi {{ $message->sender_type === 'admin' ? 'bi-shield-check' : 'bi-person' }}" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy">
                        <strong>{{ $message->sender_type === 'admin' ? 'Admin' : ($message->sender_type === 'guest' ? $ticket->guest_name : ($message->sender?->name ?? 'Customer')) }}</strong>
                        <span>{{ $message->created_at->format('M j, Y · H:i') }}</span>
                        <p>{{ $message->message }}</p>
                    </div>
                </article>
            @endforeach
        </div>
        <form class="admin-form support-admin-reply" method="POST" action="{{ route('admin.support-tickets.reply', $ticket->ticket_number) }}">
            @csrf
            <label for="admin-reply">Reply to customer</label>
            <textarea class="portal-input" id="admin-reply" name="message" rows="5" maxlength="5000" required>{{ old('message') }}</textarea>
            @error('message')<span class="admin-field-error">{{ $message }}</span>@enderror
            <button class="admin-button" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Send reply</button>
        </form>
    </section>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Ticket history</h2></div></div>
        <div class="admin-activity-list">
            @forelse ($audit as $entry)
                <article class="admin-activity-item admin-activity-item-stacked">
                    <span class="admin-activity-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy"><strong>{{ str($entry->event)->replace('_', ' ')->title() }}</strong><span>{{ $entry->actor?->name ?? 'System' }} <span class="admin-activity-separator">·</span> {{ $entry->created_at->format('M j, Y · H:i') }}</span></div>
                </article>
            @empty
                <div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit history</strong><p>Ticket changes will appear here.</p></div>
            @endforelse
        </div>
    </section>
@endsection