@extends('layouts.admin')

@section('title', 'Quick PayMoney | Referral Commission Report')

@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">CUSTOMER GROWTH / FINANCIAL REPORT</span><h1>Referral commission report</h1><p>Review credited rewards, qualification events, beneficiaries, and their immutable INR ledger entries.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.referrals.settings') }}"><i class="bi bi-gear" aria-hidden="true"></i> Referral settings</a>
    </div>

    <p class="admin-summary-note">All-time global commission amounts; report filters only affect the table below.</p>
    <section class="admin-stats admin-user-stats" aria-label="All-time referral commission amounts in INR">
        @foreach ([
            'total' => ['Total commissions · INR', 'total_amount', 'total_count'],
            'pending' => ['Pending · INR', 'pending_amount', 'pending_count'],
            'credited' => ['Credited · INR', 'credited_amount', 'credited_count'],
            'reversed' => ['Reversed · INR', 'reversed_amount', 'reversed_count'],
        ] as $key => [$label, $amountKey, $countKey])
            <article class="admin-stat">
                <span class="admin-stat-label">{{ $label }}</span>
                <strong>{{ \App\Support\Money::formatInr((string) $commissionSummary->{$amountKey}) }}</strong>
                <small>{{ number_format((int) ($commissionSummary->{$countKey} ?? $commissionSummary->total_count)) }} commissions</small>
            </article>
        @endforeach
    </section>
    <section class="admin-stats admin-user-stats" aria-label="Referral commission summary">
        <article class="admin-stat"><span class="admin-stat-label">Total commissions paid</span><strong>{{ \App\Support\Money::formatInr($totalPaid) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Qualified referrals</span><strong>{{ number_format($qualifiedReferrals) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Active levels</span><strong>{{ number_format($activeLevels) }} / 3</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Inactive levels</span><strong>{{ number_format($inactiveLevels) }} / 3</strong></article>
    </section>
    <section class="admin-stats admin-user-stats" aria-label="Paid commissions by referral level">
        @foreach ([1 => 'Level 1 paid', 2 => 'Level 2 paid', 3 => 'Level 3 paid'] as $levelNumber => $label)
            <article class="admin-stat"><span class="admin-stat-label">{{ $label }}</span><strong>{{ \App\Support\Money::formatInr((string) ($levelTotals[$levelNumber] ?? '0.00')) }}</strong></article>
        @endforeach
    </section>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">FINANCIAL HISTORY</span><h2>Referral commissions</h2><p>Search and filter qualification records. Ledger entries are immutable and linked to their original reward.</p></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.referrals.commissions') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="referral-search">Search customers, transaction reference or ledger</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="referral-search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="120" placeholder="Customer, transaction reference or ledger ID"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="referral-level">Referral level</label><select class="portal-input" id="referral-level" name="level"><option value="">All levels</option>@foreach ([1, 2, 3] as $level)<option value="{{ $level }}" @selected(($filters['level'] ?? '') === (string) $level)>Level {{ $level }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="referral-status">Commission status</label><select class="portal-input" id="referral-status" name="status"><option value="">All statuses</option>@foreach (\App\Models\ReferralCommission::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <label class="visually-hidden" for="referral-date-from">Qualified from</label><input class="portal-input" id="referral-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            <label class="visually-hidden" for="referral-date-to">Qualified to</label><input class="portal-input" id="referral-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (collect($filters)->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty())<a class="admin-button admin-button-secondary" href="{{ route('admin.referrals.commissions') }}">Clear</a>@endif
        </form>
        <div class="admin-table-meta"><span>Showing <strong>{{ $commissions->firstItem() ?? 0 }}–{{ $commissions->lastItem() ?? 0 }}</strong> of <strong>{{ $commissions->total() }}</strong> commissions</span></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th scope="col">Beneficiary</th><th scope="col">Qualified customer</th><th scope="col">Level</th><th scope="col">Commission</th><th scope="col">Qualified</th><th scope="col">Credited</th><th scope="col">Status</th><th scope="col">Source transaction</th><th scope="col">INR ledger</th></tr></thead>
                <tbody>
                    @forelse ($commissions as $commission)
                        <tr>
                            <td><div class="admin-table-primary">{{ $commission->beneficiary?->name ?? 'Customer unavailable' }}</div><div class="admin-table-secondary">{{ $commission->beneficiary?->customer_id ?? '—' }}</div></td>
                            <td><div class="admin-table-primary">{{ $commission->qualifiedUser?->name ?? 'Customer unavailable' }}</div><div class="admin-table-secondary">{{ $commission->qualifiedUser?->customer_id ?? '—' }}</div></td>
                            <td>Level {{ $commission->level }}</td>
                            <td>{{ \App\Support\Money::formatInr((string) $commission->amount) }}</td>
                            <td><time datetime="{{ $commission->qualified_at->toIso8601String() }}">{{ $commission->qualified_at->format('M j, Y · H:i') }}</time></td>
                            <td>@if ($commission->credited_at)<time datetime="{{ $commission->credited_at->toIso8601String() }}">{{ $commission->credited_at->format('M j, Y · H:i') }}</time>@else — @endif</td>
                            <td><span class="portal-badge {{ $commission->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($commission->status) }}</span></td>
                            <td>{{ $commission->triggeringExchangeRequest?->request_reference ?? $commission->triggeringWithdrawal?->request_reference ?? '—' }}</td>
                            <td>@if ($commission->ledgerEntry)<span class="admin-id">#{{ $commission->ledgerEntry->id }}</span><small>{{ \App\Support\Money::formatInr((string) $commission->ledgerEntry->amount) }} credit</small>@else — @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No referral commissions found</strong><p>Commission records matching these filters will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($commissions->hasPages())<div class="admin-pagination">{{ $commissions->links() }}</div>@endif
    </section>
@endsection
