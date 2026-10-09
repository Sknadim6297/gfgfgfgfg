@extends('layouts.admin')
@section('title', 'Quick PayMoney | Deposit Review')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT OPERATIONS / REVIEW</span><h1>Reference <span class="admin-title-id">{{ $deposit->deposit_id }}</span></h1><p>Approval is a manual administrative decision, only after external payment verification.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to deposits</a>
    </div>

    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">REQUEST SUMMARY</span><h2>Deposit details</h2></div><span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span></div>
            <dl class="admin-detail-list">
                <div><dt>Customer</dt><dd><a class="admin-inline-link" href="{{ route('admin.users.show', $deposit->user) }}">{{ $deposit->user->name }}</a><small>{{ $deposit->user->email }}</small></dd></div>
                <div><dt>Amount</dt><dd>{{ \App\Support\Money::formatUsd($deposit->amount) }}</dd></div>
                <div><dt>Transaction ID / UTR</dt><dd>{{ $deposit->transaction_reference }}</dd></div>
                <div><dt>Submitted</dt><dd>{{ $deposit->submitted_at->format('M j, Y · H:i:s') }}</dd></div>
                <div><dt>Reviewed</dt><dd>{{ $deposit->reviewed_at?->format('M j, Y · H:i:s') ?? 'Not reviewed' }}</dd></div>
                @if ($deposit->rejection_reason)<div><dt>Rejection reason</dt><dd>{{ $deposit->rejection_reason }}</dd></div>@endif
                <div><dt>Balance ledger</dt><dd>{{ $ledgerEntry ? 'Credit recorded · '.$ledgerEntry->created_at->format('M j, Y · H:i') : 'No balance credit' }}</dd></div>
            </dl>
            @if ($deposit->proof_path)
                <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.proof', $deposit) }}" target="_blank" rel="noopener"><i class="bi bi-image" aria-hidden="true"></i> View payment proof</a>
            @else
                <p class="admin-field-hint">No payment proof was attached.</p>
            @endif
        </section>

        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">MANUAL REVIEW</span><h2>Review request</h2><p class="sr-only">verify the payment externally</p><p>Externally verify the payment reference before approving.</p></div></div>
            @if ($deposit->status === 'pending')
                <form class="admin-form" method="POST" action="{{ route('admin.deposits.update', $deposit) }}" data-confirm="Only approve after verifying the payment externally. Approval credits the customer balance exactly once." data-confirm-title="Review this deposit?" data-confirm-button="Save review">
                    @csrf @method('PUT')
                    <label for="status">Decision</label>
                    <select class="portal-input" id="status" name="status" required><option value="approved">Approve — verified externally</option><option value="rejected">Reject</option></select>
                    @error('status')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <label for="rejection_reason">Rejection reason <span>(optional)</span></label>
                    <textarea class="portal-input" id="rejection_reason" name="rejection_reason" rows="4" maxlength="2000">{{ old('rejection_reason') }}</textarea>
                    @error('rejection_reason')<span class="admin-field-error">{{ $message }}</span>@enderror
                    <div class="admin-inline-notice"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p>Pending requests do not affect balances. Approval creates an immutable credit entry and updates the recorded USD balance in one database transaction.</p></div>
                    <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save review</button>
                </form>
            @else
                <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>This request has been resolved and cannot be changed. Repeated approval will never create another credit.</p></div>
            @endif
        </section>
    </div>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Request history</h2><p>Submission and administrative decisions recorded for this deposit.</p></div></div>
        <div class="admin-activity-list">
            @forelse ($audit as $entry)
                @php
                    $metadata = is_array($entry->metadata) ? $entry->metadata : [];
                    $isSubmission = $entry->event === 'deposit.submitted';
                    $isApproval = $entry->event === 'admin.deposit_approved';
                    $isReview = in_array($entry->event, ['admin.deposit_approved', 'admin.deposit_rejected'], true);
                    if ($isSubmission && filled($deposit->user->name)) {
                        $metadata = ['customer' => $deposit->user->name] + $metadata;
                    }
                    if ($isApproval) {
                        $metadata['ledger_credit'] = $ledgerEntry ? 'Recorded' : 'Not recorded';
                    }
                    $eventDate = $entry->created_at->timezone(config('app.timezone'));
                @endphp
                <article class="admin-activity-item admin-activity-item-stacked">
                    <span class="admin-activity-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy">
                        <strong>{{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong>
                        <span>
                            @if ($isSubmission)
                                Submitted by {{ $entry->actor?->name ?? 'Customer' }}
                            @elseif ($isReview)
                                Reviewed by {{ $entry->actor?->name ?? 'Administrator' }}
                            @else
                                Recorded by {{ $entry->actor?->name ?? 'System' }}
                            @endif
                            · <time datetime="{{ $eventDate->toIso8601String() }}">{{ $eventDate->format('M j, Y · H:i') }}</time>
                        </span>
                        <x-admin.audit-metadata :metadata="$metadata" :event="$entry->event" />
                    </div>
                </article>
            @empty<div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit history</strong><p>Request events will appear here once recorded.</p></div>@endforelse
        </div>
    </section>
@endsection
