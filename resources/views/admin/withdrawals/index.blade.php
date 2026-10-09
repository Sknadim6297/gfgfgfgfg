@extends('layouts.admin')
@section('title', 'Quick PayMoney | INR Withdrawals')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT OPERATIONS</span><h1>INR withdrawal requests</h1><p>Review requests and record payout status. Transfers are not automated.</p></div>
        <span class="admin-record-count"><i class="bi bi-bank" aria-hidden="true"></i> {{ number_format($withdrawals->total()) }} matching requests</span>
    </div>
    <section class="admin-panel admin-directory">
        <form class="admin-filters" method="GET" action="{{ route('admin.withdrawals.index') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="withdrawal-search">Search customer or reference</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="withdrawal-search" name="search" value="{{ request('search') }}" maxlength="120" placeholder="Search customer or reference"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="withdrawal-status">Withdrawal status</label><select class="portal-input" id="withdrawal-status" name="status"><option value="">All statuses</option>@foreach (\App\Models\WithdrawalRequest::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
        </form>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th>Reference</th><th>Customer</th><th>Customer ID</th><th>Amount (INR)</th><th>Destination</th><th>Status</th><th>Requested</th><th></th></tr></thead>
                <tbody>
                    @forelse ($withdrawals as $withdrawal)
                        <tr>
                            <td><span class="admin-id">{{ $withdrawal->request_reference }}</span></td>
                            <td><div class="admin-table-primary">{{ $withdrawal->user->name }}</div><div class="admin-table-secondary">{{ $withdrawal->user->email }}</div></td>
                            <td>{{ $withdrawal->user->customer_id }}</td>
                            <td>{{ \App\Support\Money::formatInr((string) $withdrawal->amount) }}</td>
                            <td>
                                @if ($withdrawal->payout_method === 'bank')
                                    {{ $withdrawal->bank_name }} · ending {{ substr((string) $withdrawal->bank_account_number, -4) }}
                                @else
                                    Cash
                                @endif
                            </td>
                            <td><span class="portal-badge {{ $withdrawal->status }}">{{ ucfirst($withdrawal->status) }}</span></td>
                            <td>{{ $withdrawal->requested_at->format('M j, Y · H:i') }}</td>
                            <td class="admin-table-action"><a class="admin-icon-button" href="{{ route('admin.withdrawals.show', $withdrawal) }}" aria-label="Review {{ $withdrawal->request_reference }}"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No withdrawal requests</strong><p>Requests matching these filters will appear here.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($withdrawals->hasPages())<div class="admin-pagination">{{ $withdrawals->links() }}</div>@endif
    </section>
@endsection
