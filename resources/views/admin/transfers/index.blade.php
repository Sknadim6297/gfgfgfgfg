@extends('layouts.admin')
@section('title', 'Quick PayMoney | Internal USDT Transfers')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">WALLET CONTROLS</span><h1>Internal USDT transfers</h1><p>Monitor completed member-to-member USDT transfers. Ledger entries stay immutable.</p></div>
        <a class="admin-button" href="{{ route('admin.internal-transfers.settings') }}"><i class="bi bi-sliders" aria-hidden="true"></i> Transfer limits</a>
    </div>
    <section class="admin-stats admin-user-stats" aria-label="Transfer availability">
        <article class="admin-stat"><span class="admin-stat-label">Transfers</span><strong>{{ $settings?->is_enabled ? 'Enabled' : 'Disabled' }}</strong><small>Member access</small></article>
        <article class="admin-stat"><span class="admin-stat-label">Minimum</span><strong>{{ $settings?->minimum_amount ?? '1.00000000' }}</strong><small>USDT</small></article>
        <article class="admin-stat"><span class="admin-stat-label">Maximum</span><strong>{{ $settings?->maximum_amount ?? 'No limit' }}</strong><small>USDT</small></article>
    </section>
    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT LEDGER</span><h2>Completed transfers</h2></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.internal-transfers.index') }}">
            <div class="admin-search-field">
                <label class="visually-hidden" for="transfer-search">Search reference, member, or Customer ID</label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="portal-input" id="transfer-search" name="search" value="{{ request('search') }}" maxlength="80" placeholder="Search reference, member, or Customer ID">
            </div>
            <div class="admin-filter-select">
                <label class="visually-hidden" for="transfer-status">Transfer status</label>
                <select class="portal-input" id="transfer-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\InternalUsdtTransfer::STATUSES as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <input class="portal-input" type="date" name="date_from" value="{{ request('date_from') }}" aria-label="From date">
            <input class="portal-input" type="date" name="date_to" value="{{ request('date_to') }}" aria-label="To date">
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
        </form>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead>
                    <tr><th scope="col">Reference</th><th scope="col">Sender</th><th scope="col">Recipient</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Created</th></tr>
                </thead>
                <tbody>
                    @forelse ($transfers as $transfer)
                        <tr>
                            <td><span class="admin-id">{{ $transfer->transfer_reference }}</span></td>
                            <td><div class="admin-table-primary">{{ $transfer->sender?->name }}</div><div class="admin-table-secondary">{{ $transfer->sender?->customer_id }}</div></td>
                            <td><div class="admin-table-primary">{{ $transfer->recipient?->name }}</div><div class="admin-table-secondary">{{ $transfer->recipient?->customer_id }}</div></td>
                            <td>{{ \App\Support\Money::formatUsdt((string) $transfer->amount) }} {{ $transfer->currency }}</td>
                            <td><span class="portal-badge completed"><span class="admin-badge-dot"></span>{{ ucfirst($transfer->status) }}</span></td>
                            <td><time datetime="{{ $transfer->created_at->toIso8601String() }}">{{ $transfer->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</time></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No internal transfers</strong><p>Completed member transfers will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transfers->hasPages())<div class="admin-pagination">{{ $transfers->links() }}</div>@endif
    </section>
@endsection
