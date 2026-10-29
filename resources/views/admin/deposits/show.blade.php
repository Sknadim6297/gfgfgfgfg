@extends('layouts.admin')
@section('title', 'Quick PayMoney | Deposit Details')
@section('admin-content')
    @php
        $submittedTxId = $deposit->transaction_hash ?? $deposit->transaction_reference;
        $isFinal = in_array($deposit->status, ['approved', 'rejected'], true);
    @endphp
    <div class="admin-deposit-review">
        <div class="admin-page-heading">
            <div>
                <span class="admin-eyebrow">PAYMENT OPERATIONS</span>
                <h1>Deposit Details</h1>
            </div>
            <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to deposits
            </a>
        </div>

        @if (session('status'))
            <div class="admin-inline-notice" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><p>{{ session('status') }}</p></div>
        @endif
        @if (session('error'))
            <div class="admin-field-error" role="alert">{{ session('error') }}</div>
        @endif
        @error('status')
            <div class="admin-field-error" role="alert">{{ $message }}</div>
        @enderror

        <section class="admin-panel admin-deposit-summary">
            <div class="admin-panel-heading">
                <div><span class="admin-eyebrow">DEPOSIT REQUEST</span><h2>{{ $deposit->deposit_id }}</h2></div>
            </div>
            <dl class="admin-detail-list">
                <div><dt>Deposit ID</dt><dd>{{ $deposit->deposit_id }}</dd></div>
                <div>
                    <dt>Customer</dt>
                    <dd><a class="admin-inline-link" href="{{ route('admin.users.show', $deposit->user) }}">{{ $deposit->user->name }}</a></dd>
                </div>
                <div><dt>Customer ID</dt><dd>{{ $deposit->user->customer_id }}</dd></div>
                <div>
                    <dt>Deposit amount</dt>
                    <dd>{{ \App\Support\Money::formatUsdt((string) $deposit->amount) }} USDT{{ $deposit->is_demo ? ' (DEMO)' : '' }}</dd>
                </div>
                <div><dt>Network</dt><dd>{{ $deposit->network_name ?? ($deposit->blockchain === 'BNB Smart Chain' ? 'BEP20' : 'Legacy deposit') }}</dd></div>
                <div><dt>Submitted Transaction ID</dt><dd class="admin-deposit-break">{{ $submittedTxId ?: 'Not submitted' }}</dd></div>
                <div><dt>Deposit status</dt><dd><span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span></dd></div>
                <div><dt>Deposit date</dt><dd><time datetime="{{ $deposit->submitted_at->toIso8601String() }}">{{ $deposit->submitted_at->timezone(config('app.timezone'))->format('M j, Y · H:i:s T') }}</time></dd></div>
            </dl>

            <div class="admin-deposit-actions">
                <form method="POST" action="{{ route('admin.deposits.update', $deposit) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="approved">
                    <button class="admin-button" type="submit" @disabled($isFinal)>Approve Deposit</button>
                </form>
                <form method="POST" action="{{ route('admin.deposits.update', $deposit) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="rejected">
                    <button class="admin-button admin-button-danger" type="submit" @disabled($isFinal)>Reject Deposit</button>
                </form>
            </div>
        </section>
    </div>
@endsection
