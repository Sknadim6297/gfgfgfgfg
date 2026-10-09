@extends('layouts.admin')
@section('title', 'Quick PayMoney | Sell Requests')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">OPERATIONS</span><h1>Sell requests</h1><p>Review USDT sell requests and their saved rate and accounting state. Approval credits the customer’s INR Wallet.</p></div>
        <span class="admin-record-count"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> {{ number_format($exchanges->total()) }} matching requests</span>
    </div>
    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST QUEUE</span><h2>All sell requests</h2><p>Search by reference, customer ID, or customer name.</p></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.exchanges.index') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="search">Search reference or customer</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="search" name="search" value="{{ request('search') }}" maxlength="120" placeholder="Reference, customer ID, name"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="status">Status</label><select class="portal-input" id="status" name="status"><option value="">All statuses</option>@foreach (\App\Models\ExchangeRequest::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="rate_plan">Rate slab</label><select class="portal-input" id="rate_plan" name="rate_plan"><option value="">All rate slabs</option>@foreach ($ratePlans as $plan)<option value="{{ $plan->plan_key }}" @selected(request('rate_plan') === $plan->plan_key)>{{ $plan->name }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <label class="visually-hidden" for="date_from">From date</label><input class="portal-input" id="date_from" type="date" name="date_from" value="{{ request('date_from') }}">
            <label class="visually-hidden" for="date_to">To date</label><input class="portal-input" id="date_to" type="date" name="date_to" value="{{ request('date_to') }}">
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (request()->hasAny(['search', 'status', 'rate_plan', 'date_from', 'date_to']))<a class="admin-button admin-button-secondary" href="{{ route('admin.exchanges.index') }}">Clear</a>@endif
        </form>
        <div class="admin-table-meta"><span>Showing <strong>{{ $exchanges->firstItem() ?? 0 }}–{{ $exchanges->lastItem() ?? 0 }}</strong> of <strong>{{ $exchanges->total() }}</strong> requests</span></div>
        <div class="portal-table-wrap admin-table-wrap"><table class="portal-table admin-table">
            <thead><tr><th scope="col">Reference</th><th scope="col">Customer</th><th scope="col">USDT</th><th scope="col">Rate</th><th scope="col">INR</th><th scope="col">Status</th><th scope="col">Submitted</th><th scope="col">Reviewed</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
            @forelse ($exchanges as $exchange)
                <tr>
                    <td><span class="admin-id">{{ $exchange->request_reference ?: 'Legacy request' }}</span></td>
                    <td><div class="admin-table-primary">{{ $exchange->user->name }}</div><div class="admin-table-secondary">{{ $exchange->user->customer_id }} · {{ $exchange->user->email }}</div></td>
                    <td><span class="admin-sell-usdt-amount">{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }}</span> <small class="admin-sell-usdt-unit">USDT</small></td>
                    <td><span class="admin-sell-rate">₹{{ \App\Models\ExchangeRate::formatDecimal($exchange->exchange_rate) }} <small>/ USDT</small></span></td>
                    <td>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</td>
                    <td><span class="portal-badge {{ $exchange->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($exchange->status) }}</span></td>
                    <td><time datetime="{{ $exchange->created_at->toDateString() }}">{{ $exchange->created_at->format('M j, Y') }}</time></td>
                    <td>
                        @if ($exchange->approved_at)
                            <time datetime="{{ $exchange->approved_at->toDateString() }}">{{ $exchange->approved_at->format('M j, Y') }}</time>
                        @elseif ($exchange->rejected_at)
                            <time datetime="{{ $exchange->rejected_at->toDateString() }}">{{ $exchange->rejected_at->format('M j, Y') }}</time>
                        @else
                            —
                        @endif
                    </td>
                    <td class="admin-table-action">@if ($exchange->request_reference)<a class="admin-icon-button" href="{{ route('admin.exchanges.show', $exchange) }}" aria-label="Review request {{ $exchange->request_reference }}"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>@endif</td>
                </tr>
            @empty<tr><td colspan="9"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No sell requests found</strong><p>There are no requests matching these filters.</p></div></td></tr>@endforelse
            </tbody></table></div>
        @if ($exchanges->hasPages())<div class="admin-pagination">{{ $exchanges->links() }}</div>@endif
    </section>
@endsection
