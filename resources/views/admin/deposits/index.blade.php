    @extends('layouts.admin')
@section('title', 'Quick PayMoney | Deposit Management')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT OPERATIONS</span><h1>Deposits</h1><p>Review customer submissions; verify payment externally before approval.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.deposit-settings.edit') }}"><i class="bi bi-gear" aria-hidden="true"></i> Deposit settings</a>
    </div>

    <section class="admin-stats admin-user-stats" aria-label="Deposit request statistics">
        @foreach (['all' => 'All requests', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
            <article class="admin-stat"><span class="admin-stat-label">{{ $label }}</span><strong>{{ number_format($counts[$key]) }}</strong></article>
        @endforeach
    </section>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST QUEUE</span><h2>All deposit requests</h2></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.deposits.index') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="search">Search deposit ID, customer, or reference</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="search" name="search" value="{{ request('search') }}" maxlength="150" placeholder="Search ID, customer, or transaction reference"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="status">Deposit status</label><select class="portal-input" id="status" name="status"><option value="">All statuses</option>@foreach (['pending', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (request()->filled('search') || request()->filled('status'))<a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}">Clear</a>@endif
        </form>
        <div class="admin-table-meta"><span>Showing <strong>{{ $deposits->firstItem() ?? 0 }}–{{ $deposits->lastItem() ?? 0 }}</strong> of <strong>{{ $deposits->total() }}</strong> requests</span><span>Newest first</span></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th scope="col">Reference ID</th><th scope="col">Customer</th><th scope="col">Amount</th><th scope="col">Transaction reference</th><th scope="col">Submitted</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($deposits as $deposit)
                    <tr>
                        <td><span class="admin-id">{{ $deposit->deposit_id }}</span></td>
                        <td><div class="admin-user-cell"><span class="admin-avatar admin-avatar-table" aria-hidden="true">{{ mb_substr($deposit->user->name, 0, 1) }}</span><span><strong>{{ $deposit->user->name }}</strong><small>{{ $deposit->user->email }}</small></span></div></td>
                        <td>{{ \App\Support\Money::formatUsd($deposit->amount) }}</td>
                        <td><span class="admin-reference-text">{{ $deposit->transaction_reference }}</span></td>
                        <td><time datetime="{{ $deposit->submitted_at->toIso8601String() }}">{{ $deposit->submitted_at->format('M j, Y · H:i') }}</time></td>
                        <td><span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span></td>
                        <td class="admin-table-action"><a class="admin-icon-button" href="{{ route('admin.deposits.show', $deposit) }}" aria-label="Review deposit {{ $deposit->deposit_id }}"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No deposit requests found</strong><p>Submitted requests will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($deposits->hasPages())<div class="admin-pagination">{{ $deposits->links() }}</div>@endif
    </section>
@endsection
