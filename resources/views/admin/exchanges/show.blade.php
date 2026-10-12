@extends('layouts.admin')
@section('title', 'Quick PayMoney | Sell Request Details')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">OPERATIONS / TRANSACTION HISTORY</span><h1>Sell conversion <span class="admin-title-id">{{ $exchange->request_reference }}</span></h1><p>View the confirmed rate, conversion status, and recorded wallet entries.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.exchanges.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to requests</a>
    </div>
    @if ($exchange->available_usd_before !== null)
        <div class="admin-detail-grid">
            <section class="admin-panel">
                <div class="admin-panel-heading"><div><span class="admin-eyebrow">CUSTOMER</span><h2>Customer details</h2></div><span class="portal-badge {{ $exchange->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($exchange->status) }}</span></div>
                <dl class="admin-detail-list">
                    <div><dt>Name</dt><dd><a class="admin-inline-link" href="{{ route('admin.users.show', $exchange->user) }}">{{ $exchange->user->name }}</a></dd></div>
                    <div><dt>Customer ID</dt><dd>{{ $exchange->user->customer_id }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $exchange->user->email }}</dd></div>
                </dl>
                <div class="admin-panel-heading admin-section-gap"><div><span class="admin-eyebrow">SELL DETAILS</span><h2>Saved quote</h2></div></div>
                <dl class="admin-detail-list">
                    <div><dt>USDT amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }} USDT</dd></div>
                    <div><dt>Rate slab</dt><dd>{{ $exchange->rate_plan_name }} ({{ $exchange->rate_plan_key }})</dd></div>
                    <div><dt>Applied rate</dt><dd>₹{{ \App\Models\ExchangeRate::formatDecimal($exchange->exchange_rate) }} / USDT</dd></div>
                    <div><dt>INR amount</dt><dd>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</dd></div>
                    <div><dt>Submitted</dt><dd>{{ $exchange->created_at->format('M j, Y · H:i') }}</dd></div>
                </dl>
            </section>
            <section class="admin-panel">
                <div class="admin-panel-heading"><div><span class="admin-eyebrow">BALANCE / ACCOUNTING</span><h2>Reservation state</h2></div></div>
                <dl class="admin-detail-list">
                    <div><dt>Available before request</dt><dd>{{ \App\Support\Money::formatUsd((string) $exchange->available_usd_before) }}</dd></div>
                    <div><dt>Reserved USDT</dt><dd>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }}</dd></div>
                    <div><dt>Current available USD</dt><dd>{{ \App\Support\Money::formatUsd($availableBalance) }}</dd></div>
                    <div><dt>USDT finalization</dt><dd>{{ in_array($exchange->status, ['approved', 'completed'], true) ? 'Debited from recorded balance' : ($exchange->status === 'rejected' ? 'Reservation released' : 'Held while pending') }}</dd></div>
                    <div><dt>INR accounting</dt><dd>{{ in_array($exchange->status, ['approved', 'completed'], true) ? 'Credited to recorded INR balance' : 'Not credited' }}</dd></div>
                </dl>
                @if ($exchange->status === 'pending')
                    <div class="admin-panel-heading admin-section-gap"><div><span class="admin-eyebrow">LEGACY REVIEW</span><h2>Pending request</h2><p>This request predates automatic approval. It may be rejected to release its reserved USD.</p></div></div>
                    <form class="admin-form admin-section-gap" method="POST" action="{{ route('admin.exchanges.reject', $exchange) }}">
                        @csrf
                        <label for="reason">Rejection reason <span>(required)</span></label>
                        <textarea class="portal-input" id="reason" name="reason" rows="3" maxlength="500" required>{{ old('reason') }}</textarea>
                        @error('reason')<span class="admin-field-error">{{ $message }}</span>@enderror
                        <button class="admin-button admin-button-secondary" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject Request</button>
                    </form>
                @elseif ($exchange->status === 'completed')
                    <div class="admin-inline-notice"><i class="bi bi-check-circle" aria-hidden="true"></i><p>Conversion completed{{ $exchange->completed_at ? ' on '.$exchange->completed_at->format('M j, Y · H:i') : '' }}. The confirmed USDT amount was debited and the INR amount was credited to the customer wallet.</p></div>
                @elseif ($exchange->status === 'approved')
                    <div class="admin-inline-notice"><i class="bi bi-check-circle" aria-hidden="true"></i><p>Approved {{ $exchange->approved_at?->format('M j, Y · H:i') }}{{ $exchange->approver ? ' by '.$exchange->approver->name : ' automatically' }}. INR was credited to the customer’s INR Wallet.</p></div>
                @else
                    <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>This request is {{ $exchange->status }} and cannot be changed.</p></div>
                    @if ($exchange->rejection_reason)<p><strong>Rejection reason:</strong> {{ $exchange->rejection_reason }}</p>@endif
                @endif
            </section>
        </div>
    @else
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">LEGACY EXCHANGE</span><h2>Historical request details</h2></div><span class="portal-badge {{ $exchange->status }}">{{ ucfirst($exchange->status) }}</span></div>
            <p>This historical exchange request predates the current Sell reservation workflow. Its existing review workflow remains available below.</p>
            <dl class="admin-detail-list">
                <div><dt>User</dt><dd>{{ $exchange->user->name }} · {{ $exchange->user->customer_id }} · {{ $exchange->user->email }}</dd></div>
                <div><dt>USDT amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }} USDT</dd></div>
                <div><dt>Rate</dt><dd>{{ \App\Models\ExchangeRate::formatDecimal($exchange->exchange_rate) }}</dd></div>
                <div><dt>INR amount</dt><dd>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</dd></div>
            </dl>
            @if (in_array($exchange->status, ['pending', 'processing'], true))
                <form class="admin-form" method="POST" action="{{ route('admin.exchanges.update', $exchange) }}" data-confirm="This legacy status action may finalize the existing exchange ledger workflow." data-confirm-title="Save exchange update?" data-confirm-button="Save update">
                    @csrf @method('PUT')
                    <label for="status">Next status</label>
                    <select class="portal-input" id="status" name="status" required>
                        @if ($exchange->status === 'pending')<option value="processing">Processing</option><option value="rejected">Rejected</option>@else<option value="completed">Completed</option><option value="rejected">Rejected</option>@endif
                    </select>
                    <label for="transaction_reference">Verification reference <span>(required for completion)</span></label>
                    <input class="portal-input" id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference', $exchange->transaction_reference) }}" maxlength="150">
                    <label for="admin_notes">Admin notes</label>
                    <textarea class="portal-input" id="admin_notes" name="admin_notes" rows="4" maxlength="5000">{{ old('admin_notes', $exchange->admin_notes) }}</textarea>
                    <button class="admin-button" type="submit">Save status</button>
                </form>
            @endif
        </section>
    @endif
    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Transaction history</h2><p>Events contain safe metadata for the Sell-to-INR Wallet accounting workflow.</p></div></div>
        <div class="admin-activity-list">
            @forelse ($audit as $entry)
                <article class="admin-activity-item admin-activity-item-stacked">
                    <span class="admin-activity-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy">
                        <strong>{{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong>
                        <span>{{ $entry->actor?->name ?? 'System' }} <span class="admin-activity-separator">·</span> <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</time></span>
                        <x-admin.audit-metadata :metadata="$entry->metadata" :event="$entry->event" />
                    </div>
                </article>
            @empty<div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit history</strong><p>Request events will appear here once recorded.</p></div>@endforelse
        </div>
    </section>
@endsection
