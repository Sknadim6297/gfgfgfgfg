@extends('layouts.admin')
@section('title', 'Quick PayMoney | Withdrawal Review')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT OPERATIONS / REVIEW</span><h1>Withdrawal <span class="admin-title-id">{{ $withdrawal->request_reference }}</span></h1><p>Mark completed only after recording an externally executed payout reference.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.withdrawals.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to requests</a>
    </div>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST SUMMARY</span><h2>Withdrawal details</h2></div><span class="portal-badge {{ $withdrawal->status }}">{{ ucfirst($withdrawal->status) }}</span></div>
            <dl class="admin-detail-list">
                <div><dt>Customer</dt><dd><a class="admin-inline-link" href="{{ route('admin.users.show', $withdrawal->user) }}">{{ $withdrawal->user->name }}</a><small>{{ $withdrawal->user->email }} · {{ $withdrawal->user->customer_id }}</small></dd></div>
                <div><dt>Amount</dt><dd>{{ \App\Support\Money::formatInr((string) $withdrawal->amount) }}</dd></div>
                <div><dt>Requested</dt><dd>{{ $withdrawal->requested_at->format('M j, Y · H:i:s') }}</dd></div>
                <div><dt>Payment method</dt><dd>{{ $withdrawal->payout_method === 'bank' ? 'Bank Account' : 'Cash' }}</dd></div>
                @if ($withdrawal->payout_method === 'bank')
                    <div><dt>Account holder</dt><dd>{{ $withdrawal->bank_account_holder }}</dd></div>
                    <div><dt>Bank</dt><dd>{{ $withdrawal->bank_name }}</dd></div>
                    <div><dt>Account number</dt><dd>{{ $withdrawal->bank_account_number }}</dd></div>
                    <div><dt>IFSC</dt><dd>{{ $withdrawal->bank_ifsc_code }}</dd></div>
                    <div><dt>Branch / type</dt><dd>{{ $withdrawal->bank_branch_name ?: '—' }} / {{ $withdrawal->bank_account_type ?: '—' }}</dd></div>
                @else
                    <div><dt>Cash handling</dt><dd>Administrator-managed; request creation does not confirm a payout.</dd></div>
                @endif
                <div><dt>Available INR after reservation</dt><dd>{{ \App\Support\Money::formatInr((string) $withdrawal->user->inr_balance) }}</dd></div>
            </dl>
        </section>
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">MANUAL REVIEW</span><h2>Update request</h2><p>Rejecting refunds the reserved INR balance. Completion only records an administrator-reported external payout.</p></div></div>
            @if (in_array($withdrawal->status, ['pending', 'processing'], true))
                <form class="admin-form" method="POST" action="{{ route('admin.withdrawals.update', $withdrawal) }}">
                    @csrf @method('PUT')
                    <label for="status">Next status</label>
                    <select class="portal-input" id="status" name="status" required>
                        @if ($withdrawal->status === 'pending')<option value="processing">Processing</option><option value="rejected">Rejected (refund balance)</option>@else<option value="completed">Completed</option><option value="rejected">Rejected (refund balance)</option>@endif
                    </select>
                    @error('status')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <label for="transaction_reference">External payout reference <span>(required for completion)</span></label>
                    <input class="portal-input" id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference', $withdrawal->transaction_reference) }}" maxlength="150">
                    @error('transaction_reference')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <label for="admin_notes">Admin notes</label>
                    <textarea class="portal-input" id="admin_notes" name="admin_notes" rows="4" maxlength="5000">{{ old('admin_notes', $withdrawal->admin_notes) }}</textarea>
                    @error('admin_notes')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <label for="rejection_reason">Customer rejection reason <span>(required when rejecting)</span></label>
                    <textarea class="portal-input" id="rejection_reason" name="rejection_reason" rows="3" maxlength="500">{{ old('rejection_reason', $withdrawal->rejection_reason) }}</textarea>
                    @error('rejection_reason')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save status</button>
                </form>
            @else
                <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>This request is terminal. No automatic transfer was made.</p></div>
                @if ($withdrawal->admin_notes)<p>{{ $withdrawal->admin_notes }}</p>@endif
            @endif
        </section>
    </div>
    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Request history</h2></div></div>
        <div class="admin-activity-list">
            @forelse ($audit as $entry)
                <article class="admin-activity-item admin-activity-item-stacked"><span class="admin-activity-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div class="admin-activity-copy"><strong>{{ str_replace('_', ' ', $entry->event) }}</strong><span>{{ $entry->actor?->name ?? 'System' }} · {{ $entry->created_at->format('M j, Y · H:i') }}</span><pre class="admin-pre">{{ json_encode($entry->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div></article>
            @empty<div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit history</strong></div>@endforelse
        </div>
    </section>
@endsection
