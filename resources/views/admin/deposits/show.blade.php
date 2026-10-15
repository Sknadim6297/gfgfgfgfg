@extends('layouts.admin')
@section('title', 'Quick PayMoney | Deposit Verification')
@section('admin-content')
    @php
        $submittedTxId = $deposit->transaction_hash ?? $deposit->transaction_reference;
    @endphp
    <div class="admin-deposit-review">
        <div class="admin-page-heading">
            <div>
                <span class="admin-eyebrow">PAYMENT OPERATIONS / MANUAL VERIFICATION</span>
                <h1>Deposit <span class="admin-title-id">{{ $deposit->deposit_id }}</span></h1>
                <p>Verify the payment externally on BEP20. A customer-provided transaction hash is a claim only.</p>
            </div>
            <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to deposits</a>
        </div>
        @if (session('status'))
            <div class="admin-inline-notice" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><p>{{ session('status') }}</p></div>
        @endif

        <div class="admin-deposit-review-grid">
            <section class="admin-panel admin-deposit-summary">
                <div class="admin-panel-heading">
                    <div><span class="admin-eyebrow">REQUEST SUMMARY</span><h2>Deposit details</h2></div>
                    <span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span>
                </div>
                <dl class="admin-detail-list">
                    <div>
                        <dt>Deposit ID</dt>
                        <dd>
                            <span class="admin-deposit-copy-value"><span class="admin-deposit-raw-value">{{ $deposit->deposit_id }}</span>
                                <button class="admin-deposit-copy" type="button" data-copy-value="{{ $deposit->deposit_id }}" aria-label="Copy deposit ID"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button>
                            </span>
                        </dd>
                    </div>
                    <div><dt>Customer</dt><dd><a class="admin-inline-link" href="{{ route('admin.users.show', $deposit->user) }}">{{ $deposit->user->name }}</a><small>Customer ID {{ $deposit->user->customer_id }} · {{ $deposit->user->email }}</small></dd></div>
                    <div><dt>Requested amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) $deposit->amount) }} USDT</dd></div>
                    <div><dt>Deposit status</dt><dd><span class="portal-badge {{ $deposit->status }}">{{ ucfirst($deposit->status) }}</span></dd></div>
                    <div><dt>Network</dt><dd>{{ $deposit->network_name ?? ($deposit->blockchain === 'BNB Smart Chain' ? 'BEP20' : 'Legacy deposit') }}</dd></div>
                    <div>
                        <dt>Token contract snapshot</dt>
                        <dd>
                            @if ($deposit->token_contract_address)
                                <span class="admin-deposit-copy-value admin-deposit-break"><span class="admin-deposit-raw-value">{{ $deposit->token_contract_address }}</span>
                                    <button class="admin-deposit-copy" type="button" data-copy-value="{{ $deposit->token_contract_address }}" aria-label="Copy token contract address"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button>
                                </span>
                            @else
                                Not stored for this legacy deposit
                            @endif
                        </dd>
                    </div>
                    <div><dt>Payment recipient snapshot</dt><dd>{{ $deposit->recipient_name_snapshot ?? 'Not stored for this legacy deposit' }}</dd></div>
                    <div>
                        <dt>Receiving wallet snapshot</dt>
                        <dd>
                            @if ($deposit->receiving_wallet_address)
                                <span class="admin-deposit-copy-value admin-deposit-break"><span class="admin-deposit-raw-value">{{ $deposit->receiving_wallet_address }}</span>
                                    <button class="admin-deposit-copy" type="button" data-copy-value="{{ $deposit->receiving_wallet_address }}" aria-label="Copy receiving wallet address"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button>
                                </span>
                            @else
                                Not stored for this legacy deposit
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Customer submitted TxID</dt>
                        <dd>
                            @if ($submittedTxId)
                                <span class="admin-deposit-copy-value admin-deposit-break"><span class="admin-deposit-raw-value">{{ $submittedTxId }}</span>
                                    <button class="admin-deposit-copy" type="button" data-copy-value="{{ $submittedTxId }}" aria-label="Copy customer submitted transaction ID"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button>
                                </span>
                            @else
                                Not submitted
                            @endif
                        </dd>
                    </div>
                    @if ($deposit->transaction_hash)
                        <div><dt>Blockchain explorer</dt><dd><a class="admin-inline-link" href="https://bscscan.com/tx/{{ rawurlencode($deposit->transaction_hash) }}" target="_blank" rel="noopener noreferrer">Open transaction on BscScan <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></dd></div>
                    @endif
                    <div><dt>Deposit created</dt><dd><time datetime="{{ $deposit->submitted_at->toIso8601String() }}">{{ $deposit->submitted_at->timezone(config('app.timezone'))->format('M j, Y · H:i:s T') }}</time></dd></div>
                    <div><dt>TxID submitted</dt><dd>{{ $transactionHashSubmittedAt?->timezone(config('app.timezone'))->format('M j, Y · H:i:s T') ?? ($deposit->transaction_reference ? 'Legacy reference submitted with request' : 'Not submitted') }}</dd></div>
                    <div><dt>Review status</dt><dd>{{ ucfirst($deposit->status) }}@if($deposit->reviewer) · {{ $deposit->reviewer->name }}@endif</dd></div>
                    <div><dt>Reviewed date</dt><dd>{{ $deposit->reviewed_at?->timezone(config('app.timezone'))->format('M j, Y · H:i:s T') ?? 'Not reviewed' }}</dd></div>
                    @if ($deposit->blockchain_confirmations !== null)<div><dt>Confirmations recorded</dt><dd>{{ number_format($deposit->blockchain_confirmations) }}</dd></div>@endif
                    @if ($deposit->transaction_event_index !== null)<div><dt>Transfer event index</dt><dd>{{ $deposit->transaction_event_index }}</dd></div>@endif
                    @if ($deposit->rejection_reason)<div><dt>Rejection reason</dt><dd>{{ $deposit->rejection_reason }}</dd></div>@endif
                    <div><dt>Wallet ledger status</dt><dd>{{ $ledgerEntry ? 'Credit recorded · '.$ledgerEntry->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i T') : 'No balance credit' }}</dd></div>
                </dl>

                @if ($deposit->proof_path)
                    <a class="admin-button admin-button-secondary admin-deposit-legacy-proof" href="{{ route('admin.deposits.proof', $deposit) }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-image" aria-hidden="true"></i> View legacy payment proof</a>
                @endif
            </section>

            <section class="admin-panel admin-deposit-verification">
                <div class="admin-panel-heading"><div><span class="admin-eyebrow">MANUAL REVIEW</span><h2>Verification checklist</h2><p>Verify the transaction directly on BEP20 mainnet. Do not rely on the customer’s claim alone.</p></div></div>
                @if ($deposit->network_name === 'BEP20' && $deposit->status === 'pending')
                    <div class="admin-inline-notice"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p>Approval remains a manual attestation. The system does not query the blockchain or claim to verify the transaction automatically.</p></div>
                @endif
                @if ($deposit->status === 'pending')
                    <form class="admin-form admin-deposit-review-form" method="POST" action="{{ route('admin.deposits.update', $deposit) }}" data-deposit-review-form data-confirm="Only approve after independently verifying every required transaction detail. This creates an irreversible wallet credit." data-confirm-title="Review this deposit?" data-confirm-button="Save review">
                        @csrf @method('PUT')
                        <div class="admin-deposit-input-grid">
                            <div class="admin-deposit-field">
                                <label for="status">Decision</label>
                                <select class="portal-input" id="status" name="status" data-review-decision required>
                                    <option value="approved" @selected(old('status') !== 'rejected')>Approve — verified externally</option>
                                    <option value="rejected" @selected(old('status') === 'rejected')>Reject</option>
                                </select>
                                @error('status')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                            @if ($deposit->network_name === 'BEP20')
                                <div class="admin-deposit-field">
                                    <label for="verified_network">Manually verified network</label>
                                    <select class="portal-input" id="verified_network" name="verified_network" data-verification-required><option value="BEP20" @selected(old('verified_network') === 'BEP20')>BEP20 mainnet</option></select>
                                    @error('verified_network')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                            @endif
                            @if ($deposit->network_name === 'BEP20')
                                <div class="admin-deposit-field">
                                    <label for="verified_token_contract_address">Verified token contract</label>
                                    <input class="portal-input admin-deposit-monospace" id="verified_token_contract_address" name="verified_token_contract_address" data-verification-required value="{{ old('verified_token_contract_address', $deposit->token_contract_address) }}" maxlength="42" autocomplete="off" spellcheck="false">
                                    @error('verified_token_contract_address')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="admin-deposit-field">
                                    <label for="verified_recipient_address">Verified recipient</label>
                                    <input class="portal-input admin-deposit-monospace" id="verified_recipient_address" name="verified_recipient_address" data-verification-required value="{{ old('verified_recipient_address', $deposit->receiving_wallet_address) }}" maxlength="42" autocomplete="off" spellcheck="false">
                                    @error('verified_recipient_address')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="admin-deposit-field">
                                    <label for="verified_amount">Verified amount (USDT)</label>
                                    <input class="portal-input" id="verified_amount" name="verified_amount" data-verification-required inputmode="decimal" value="{{ old('verified_amount', $deposit->amount) }}" maxlength="21">
                                    @error('verified_amount')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="admin-deposit-field">
                                    <label for="transaction_event_index">Transfer event index</label>
                                    <input class="portal-input" id="transaction_event_index" name="transaction_event_index" data-verification-required type="number" min="0" step="1" value="{{ old('transaction_event_index') }}">
                                    @error('transaction_event_index')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                                <div class="admin-deposit-field">
                                    <label for="blockchain_confirmations">Observed confirmations</label>
                                    <input class="portal-input" id="blockchain_confirmations" name="blockchain_confirmations" data-verification-required type="number" min="1" step="1" value="{{ old('blockchain_confirmations') }}">
                                    @error('blockchain_confirmations')<span class="admin-field-error">{{ $message }}</span>@enderror
                                </div>
                            @endif
                            <div class="admin-deposit-field admin-deposit-field-full">
                                <label for="rejection_reason">Rejection reason <span>(required when rejecting)</span></label>
                                <textarea class="portal-input" id="rejection_reason" name="rejection_reason" rows="3" maxlength="2000">{{ old('rejection_reason') }}</textarea>
                                @error('rejection_reason')<span class="admin-field-error">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        @if ($deposit->network_name === 'BEP20')
                            <fieldset class="deposit-verification-checklist" data-bep20-verification>
                                <legend>Confirm every item after manual verification</legend>
                                @foreach ([
                                    'network' => 'Transaction exists on BEP20 mainnet.',
                                    'receipt' => 'Transaction receipt indicates success.',
                                    'contract' => 'USDT token contract matches the independently verified contract.',
                                    'recipient' => 'Transfer recipient matches the original receiving wallet snapshot.',
                                    'amount' => 'Transfer amount matches the requested USDT amount.',
                                    'confirmations' => 'Required blockchain confirmations are satisfied.',
                                    'unique_transfer' => 'This transfer event has not previously been credited.',
                                ] as $key => $label)
                                    <label class="deposit-check-item"><input type="checkbox" name="verification_checks[{{ $key }}]" value="1"><span>{{ $label }}</span></label>
                                @endforeach
                                @error('verification_checks')<span class="admin-field-error">{{ $message }}</span>@enderror
                                @foreach (['network', 'receipt', 'contract', 'recipient', 'amount', 'confirmations', 'unique_transfer'] as $key)
                                    @error('verification_checks.'.$key)<span class="admin-field-error">{{ $message }}</span>@enderror
                                @endforeach
                            </fieldset>
                        @else
                            <div class="admin-inline-notice"><i class="bi bi-info-circle" aria-hidden="true"></i><p>This is a legacy deposit without a BEP20 settings snapshot. The existing manual legacy approval process is retained.</p></div>
                        @endif

                        <div class="admin-inline-notice"><i class="bi bi-shield-lock" aria-hidden="true"></i><p>Approval creates one immutable USDT ledger credit and updates the customer balance atomically. A TxID by itself never credits funds.</p></div>
                        <button class="admin-button admin-deposit-submit" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save review</button>
                    </form>
                @else
                    <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>This request has been resolved and cannot be changed. Repeated approval never creates another credit.</p></div>
                @endif
            </section>
        </div>

        <section class="admin-panel admin-section-gap">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">PAYMENT SETTINGS SNAPSHOT</span><h2>Request snapshot</h2><p>These are the settings saved when the deposit request was created.</p></div></div>
            <dl class="admin-detail-list admin-deposit-snapshot">
                <div><dt>Network</dt><dd>{{ $deposit->network_name ?? ($deposit->blockchain === 'BNB Smart Chain' ? 'BEP20' : 'Not stored for legacy request') }}</dd></div>
                <div><dt>Token</dt><dd>{{ $deposit->token_symbol ?? 'Not stored for legacy request' }}</dd></div>
                <div><dt>Contract</dt><dd class="admin-deposit-break">{{ $deposit->token_contract_address ?? 'Not stored for legacy request' }}</dd></div>
                <div><dt>Receiving wallet</dt><dd class="admin-deposit-break">{{ $deposit->receiving_wallet_address ?? 'Not stored for legacy request' }}</dd></div>
                <div><dt>Minimum at request time</dt><dd>{{ $deposit->minimum_amount_snapshot ? \App\Support\Money::formatUsdt((string) $deposit->minimum_amount_snapshot).' USDT' : 'Not stored for legacy request' }}</dd></div>
                <div><dt>Instructions</dt><dd>{{ $deposit->instructions_snapshot ? str_replace('BNB Smart Chain', 'BEP20', $deposit->instructions_snapshot) : 'Not stored for legacy request' }}</dd></div>
            </dl>
        </section>

        <section class="admin-panel admin-section-gap admin-deposit-audit">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Admin review history</h2><p>Customer claims, verification records, and administrative decisions.</p></div></div>
            <div class="admin-activity-list">
                @forelse ($audit as $entry)
                    @php
                        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
                        $isSubmission = $entry->event === 'deposit.submitted';
                        $isReview = in_array($entry->event, ['admin.deposit_approved', 'admin.deposit_rejected'], true);
                        if ($isSubmission && filled($deposit->user->name)) $metadata = ['customer' => $deposit->user->name] + $metadata;
                        if ($entry->event === 'admin.deposit_approved') $metadata['ledger_credit'] = $ledgerEntry ? 'Recorded' : 'Not recorded';
                        $eventDate = $entry->created_at->timezone(config('app.timezone'));
                    @endphp
                    <article class="admin-activity-item admin-activity-item-stacked admin-deposit-audit-item">
                        <span class="admin-activity-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                        <div class="admin-activity-copy">
                            <div class="admin-deposit-audit-heading">
                                <strong>{{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong>
                                <span class="admin-deposit-audit-reference">Deposit {{ $deposit->deposit_id }}</span>
                            </div>
                            <span>
                                @if ($isSubmission) Submitted by {{ $entry->actor?->name ?? 'Customer' }}
                                @elseif ($isReview) Reviewed by {{ $entry->actor?->name ?? 'Administrator' }}
                                @else Recorded by {{ $entry->actor?->name ?? 'System' }}
                                @endif
                                · <time datetime="{{ $eventDate->toIso8601String() }}">{{ $eventDate->format('M j, Y · H:i T') }}</time>
                            </span>
                            <x-admin.audit-metadata :metadata="$metadata" :event="$entry->event" />
                        </div>
                    </article>
                @empty
                    <div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit history</strong><p>Request events will appear here once recorded.</p></div>
                @endforelse
            </div>
        </section>
    </div>
@endsection

@section('scripts')
    <script>
        (() => {
            const root = document.querySelector('.admin-deposit-review');
            const form = root?.querySelector('[data-deposit-review-form]');
            const decision = form?.querySelector('[data-review-decision]');
            const checks = form?.querySelector('[data-bep20-verification]');
            const reason = form?.querySelector('#rejection_reason');

            root?.querySelectorAll('[data-copy-value]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const feedback = button.querySelector('[data-copy-feedback]');
                    try {
                        await navigator.clipboard.writeText(button.dataset.copyValue);
                        feedback.textContent = 'Copied';
                    } catch {
                        const input = document.createElement('textarea');
                        input.value = button.dataset.copyValue;
                        input.setAttribute('readonly', '');
                        input.style.position = 'fixed';
                        input.style.opacity = '0';
                        document.body.appendChild(input);
                        input.select();
                        const copied = document.execCommand('copy');
                        input.remove();
                        feedback.textContent = copied ? 'Copied' : 'Copy failed';
                    }
                    window.setTimeout(() => { if (feedback) feedback.textContent = ''; }, 1800);
                });
            });

            if (!form || !decision) return;

            const updateRequirements = () => {
                const approving = decision.value === 'approved';
                if (checks) {
                    checks.querySelectorAll('input').forEach((input) => input.required = approving);
                }
                form.querySelectorAll('[data-verification-required]').forEach((input) => input.required = approving);
                if (reason) reason.required = !approving;
                form.dataset.confirm = approving
                    ? 'Only approve after independently verifying every required transaction detail. This creates an irreversible wallet credit.'
                    : 'Reject this deposit request? No wallet credit will be issued.';
                form.dataset.confirmTitle = approving ? 'Approve this deposit?' : 'Reject this deposit?';
                form.dataset.confirmButton = approving ? 'Approve deposit' : 'Reject deposit';
            };

            decision.addEventListener('change', updateRequirements);
            form.addEventListener('submit', (event) => {
                if (form.dataset.confirmed !== 'true') return;
                const submit = form.querySelector('button[type="submit"]');
                if (submit?.disabled) {
                    event.preventDefault();
                    return;
                }
                if (submit) {
                    submit.disabled = true;
                    submit.setAttribute('aria-disabled', 'true');
                    submit.innerHTML = '<span class="admin-deposit-spinner" aria-hidden="true"></span> Saving review…';
                }
            });
            updateRequirements();
        })();
    </script>
@endsection
