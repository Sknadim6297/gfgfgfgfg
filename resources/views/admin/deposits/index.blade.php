@extends('layouts.admin')
@section('title', 'Quick PayMoney | Deposit Management')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT OPERATIONS</span><h1>Deposit requests</h1><p>Manually verify each BEP20 transaction before approving a wallet credit.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.deposit-settings.edit') }}"><i class="bi bi-gear" aria-hidden="true"></i> Deposit settings</a>
    </div>

    <p class="admin-summary-note">All-time request totals. Only approved amounts count as credited USDT; filters affect the queue below.</p>
    <section class="admin-stats admin-user-stats" aria-label="Deposit verification summary">
        @foreach ([
            ['Total Deposit Requests', number_format((int) $summary->total_count), 'All statuses'],
            ['Pending Verification', number_format((int) $summary->pending_count), 'Awaiting admin review'],
            ['Approved Deposits', number_format((int) $summary->approved_count), 'Verified and credited'],
            ['Rejected Deposits', number_format((int) $summary->rejected_count), 'Not credited'],
            ['Total Approved USDT', \App\Support\Money::formatUsdt((string) $summary->approved_amount).' USDT', 'Approved status only'],
        ] as [$label, $value, $description])
            <article class="admin-stat"><span class="admin-stat-label">{{ $label }}</span><strong>{{ $value }}</strong><small>{{ $description }}</small></article>
        @endforeach
    </section>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST QUEUE</span><h2>Deposit history</h2></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.deposits.index') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="search">Search deposit, customer, or transaction hash</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="search" name="search" value="{{ request('search') }}" maxlength="150" placeholder="Search ID, customer, or transaction hash"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="status">Deposit status</label><select class="portal-input" id="status" name="status"><option value="">All statuses</option>@foreach (\App\Models\Deposit::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <label class="admin-date-filter"><span class="visually-hidden">From date</span><input class="portal-input" type="date" name="date_from" value="{{ request('date_from') }}" aria-label="From date"></label>
            <label class="admin-date-filter"><span class="visually-hidden">To date</span><input class="portal-input" type="date" name="date_to" value="{{ request('date_to') }}" aria-label="To date"></label>
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (request()->filled('search') || request()->filled('status') || request()->filled('date_from') || request()->filled('date_to'))<a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}">Clear</a>@endif
        </form>
        <div class="admin-table-meta"><span>Showing <strong>{{ $deposits->firstItem() ?? 0 }}–{{ $deposits->lastItem() ?? 0 }}</strong> of <strong>{{ $deposits->total() }}</strong> requests</span><span>Newest first</span></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th scope="col">Deposit ID</th><th scope="col">Customer</th><th scope="col">Amount</th><th scope="col">Network</th><th scope="col">Transaction Hash</th><th scope="col">Submitted</th><th scope="col">Reviewed</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($deposits as $deposit)
                    <tr>
                        <td><span class="admin-id">{{ $deposit->deposit_id }}</span></td>
                        <td><div class="admin-user-cell"><span class="admin-avatar admin-avatar-table" aria-hidden="true">{{ mb_substr($deposit->user->name, 0, 1) }}</span><span><strong>{{ $deposit->user->name }}</strong><small>{{ $deposit->user->customer_id }} · {{ $deposit->user->email }}</small></span></div></td>
                        <td>{{ \App\Support\Money::formatUsdt((string) $deposit->amount) }} USDT</td>
                        <td>{{ $deposit->network_name ?? 'Legacy' }}</td>
                        <td><span class="admin-reference-text">{{ $deposit->transaction_hash ?? $deposit->transaction_reference ?? 'Not submitted' }}</span></td>
                        <td><time datetime="{{ $deposit->submitted_at->toIso8601String() }}">{{ $deposit->submitted_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</time></td>
                        <td>{{ $deposit->reviewed_at?->timezone(config('app.timezone'))->format('M j, Y · H:i') ?? '—' }}</td>
                        <td><span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span></td>
                        <td class="admin-table-action"><a class="admin-icon-button" href="{{ route('admin.deposits.show', $deposit) }}" aria-label="Review deposit {{ $deposit->deposit_id }}"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No deposit requests found</strong><p>Customer requests will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($deposits->hasPages())<div class="admin-pagination">{{ $deposits->links() }}</div>@endif
    </section>
@endsection
