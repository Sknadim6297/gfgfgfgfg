@extends('layouts.admin')
@section('title', 'Quick PayMoney | Support Tickets')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">CUSTOMER CARE</span><h1>Support tickets</h1><p>Review customer requests, reply, and manage ticket priority and status.</p></div>
        <span class="admin-record-count"><i class="bi bi-headset" aria-hidden="true"></i> {{ number_format($tickets->total()) }} matching tickets</span>
    </div>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">SUPPORT QUEUE</span><h2>All tickets</h2></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.support-tickets.index') }}">
            <div class="admin-search-field">
                <label class="visually-hidden" for="ticket-search">Search ticket, customer, or email</label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="portal-input" id="ticket-search" name="search" value="{{ request('search') }}"
                    maxlength="150" placeholder="Search ticket, customer, or email">
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="ticket-status">Ticket status</label>
                <select class="portal-input" id="ticket-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\SupportTicket::STATUSES as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="ticket-category">Ticket category</label>
                <select class="portal-input" id="ticket-category" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="ticket-priority">Ticket priority</label>
                <select class="portal-input" id="ticket-priority" name="priority">
                    <option value="">All priorities</option>
                    @foreach (\App\Models\SupportTicket::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(request('priority') === $priority)>{{ ucfirst($priority) }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (request()->hasAny(['search', 'status', 'category', 'priority']))
                <a class="admin-button admin-button-secondary" href="{{ route('admin.support-tickets.index') }}">Clear</a>
            @endif
        </form>

        <div class="admin-table-meta"><span>Showing <strong>{{ $tickets->firstItem() ?? 0 }}–{{ $tickets->lastItem() ?? 0 }}</strong> of <strong>{{ $tickets->total() }}</strong> tickets</span></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead>
                    <tr><th scope="col">Ticket</th><th scope="col">Customer</th><th scope="col">Subject</th><th scope="col">Category</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td><span class="admin-id">{{ $ticket->ticket_number }}</span></td>
                            <td>
                                <div class="admin-table-primary">{{ $ticket->user?->name ?? $ticket->guest_name }}</div>
                                <div class="admin-table-secondary">{{ $ticket->user?->email ?? $ticket->guest_email }}</div>
                            </td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->category }}</td>
                            <td><span class="portal-badge {{ $ticket->priority }}"><span class="admin-badge-dot"></span>{{ ucfirst($ticket->priority) }}</span></td>
                            <td><span class="portal-badge {{ $ticket->status }}"><span class="admin-badge-dot"></span>{{ str($ticket->status)->replace('_', ' ')->title() }}</span></td>
                            <td><time datetime="{{ $ticket->updated_at->toIso8601String() }}">{{ $ticket->updated_at->format('M j, Y · H:i') }}</time></td>
                            <td class="admin-table-action">
                                <a class="admin-icon-button" href="{{ route('admin.support-tickets.show', $ticket->ticket_number) }}" aria-label="Open ticket {{ $ticket->ticket_number }}">
                                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No support tickets found</strong><p>New customer requests will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tickets->hasPages())<div class="admin-pagination">{{ $tickets->links() }}</div>@endif
    </section>
@endsection