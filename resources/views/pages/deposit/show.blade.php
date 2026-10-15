@extends('layouts.app')

@section('title', 'Quick PayMoney | Deposit Details')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/deposit.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="deposit-page deposit-result-page deposit-details-page">
            <a class="deposit-detail-back" href="{{ route('deposit.create') }}" aria-label="Back to Deposit USDT"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
            <div class="deposit-detail-heading">
                <span class="deposit-eyebrow">SECURE BLOCKCHAIN DEPOSIT</span>
                <h1>Deposit Details</h1>
                <p>Send USDT to the address below</p>
            </div>

            @if (session('status'))
                <div class="deposit-success-notice" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><p>{{ session('status') }}</p></div>
            @endif

            <section class="deposit-details-card" aria-label="Deposit payment details">
                <div class="deposit-summary-cards">
                    <article class="deposit-summary-card">
                        <span><i class="bi bi-currency-dollar" aria-hidden="true"></i> Deposit Amount</span>
                        <strong>{{ \App\Support\Money::formatUsdt((string) $deposit->amount) }} <small>USDT</small></strong>
                    </article>
                    <article class="deposit-summary-card deposit-network-summary-card">
                        @if ($deposit->network_logo_path)
                            <img src="{{ route('deposits.image', [$deposit, 'logo']) }}" alt="BEP20 network logo">
                        @else
                            <span class="deposit-choice-logo deposit-choice-fallback" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                        @endif
                        <span class="deposit-summary-network-copy"><small>Network</small><strong>{{ $deposit->network_name ?? ($deposit->blockchain === 'BNB Smart Chain' ? 'BEP20' : 'Legacy deposit') }}</strong></span>
                    </article>
                </div>

                <div class="deposit-detail-columns">
                    <section class="deposit-qr-column" aria-label="Wallet QR code">
                        @if ($deposit->qr_snapshot_path)
                            <a class="deposit-qr-link deposit-qr-large" href="{{ route('deposits.image', [$deposit, 'qr']) }}" target="_blank" rel="noopener" aria-label="Open deposit QR code">
                                <img class="deposit-qr-image" src="{{ route('deposits.image', [$deposit, 'qr']) }}" alt="QR code for the original configured receiving wallet">
                            </a>
                        @else
                            <div class="deposit-qr-unavailable"><i class="bi bi-qr-code" aria-hidden="true"></i><span>QR code unavailable</span></div>
                        @endif
                        <p>Scan the QR code with your wallet app</p>
                    </section>

                    <div class="deposit-payment-column">
                        <div class="deposit-copy-field">
                            <span class="deposit-detail-label">Deposit Address</span>
                            <strong class="deposit-copy-value deposit-monospace" id="deposit-wallet-address">{{ $deposit->receiving_wallet_address ?? 'Address not available for this deposit' }}</strong>
                            @if ($deposit->receiving_wallet_address)
                                <button class="deposit-copy-button deposit-copy-wide" type="button" data-copy-value="{{ $deposit->receiving_wallet_address }}" data-copy-feedback-target="address-copy-feedback"><i class="bi bi-copy" aria-hidden="true"></i> Copy Address</button>
                                <span class="deposit-copy-feedback" id="address-copy-feedback" role="status" aria-live="polite"></span>
                            @endif
                        </div>

                        <div class="deposit-identifiers-grid">
                            <div class="deposit-copy-field">
                                <span class="deposit-detail-label">Deposit ID</span>
                                <strong class="deposit-copy-value deposit-monospace" id="deposit-id-value">{{ $deposit->deposit_id }}</strong>
                                <button class="deposit-copy-button" type="button" data-copy-value="{{ $deposit->deposit_id }}" data-copy-feedback-target="deposit-id-copy-feedback"><i class="bi bi-copy" aria-hidden="true"></i> Copy Deposit ID</button>
                                <span class="deposit-copy-feedback" id="deposit-id-copy-feedback" role="status" aria-live="polite"></span>
                            </div>
                            <div class="deposit-copy-field">
                                <span class="deposit-detail-label">Created Time</span>
                                <strong class="deposit-copy-value">{{ $deposit->submitted_at->timezone(config('app.timezone'))->format('M j, Y · H:i:s') }}</strong>
                            </div>
                        </div>

                        <div class="deposit-status-row">
                            <span class="deposit-detail-label">Current Deposit Status</span>
                            <span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ $deposit->customerStatusLabel() }}</span>
                        </div>

                        @if ($deposit->transaction_hash)
                            <div class="deposit-submitted-hash"><span class="deposit-detail-label">Submitted Transaction ID (TxID)</span><strong class="deposit-monospace">{{ $deposit->transaction_hash }}</strong></div>
                        @endif

                        @if ($deposit->network_name === 'BEP20' && $deposit->status === 'pending' && ! $deposit->transaction_hash)
                            <form class="deposit-hash-form deposit-transaction-form" method="POST" action="{{ route('deposits.transaction-hash.store', $deposit) }}" data-deposit-submit>
                                @csrf
                                <input type="hidden" name="submission_key" value="{{ $deposit->submission_key }}">
                                <label for="transaction_hash">Transaction ID (TxID)</label>
                                <input class="portal-input deposit-monospace" id="transaction_hash" name="transaction_hash" type="text" inputmode="text" autocomplete="off" spellcheck="false" placeholder="0x…" maxlength="66" value="{{ old('transaction_hash') }}" aria-describedby="@if ($errors->has('transaction_hash')) transaction-hash-error @endif" required>
                                @error('transaction_hash')<span class="deposit-field-error" id="transaction-hash-error">{{ $message }}</span>@enderror
                                <button class="deposit-gradient-button" type="submit"><span data-submit-label>Submit Transaction ID</span><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="deposit-information-grid">
                    <aside class="deposit-info-card deposit-important-card">
                        <h2><i class="bi bi-info-circle" aria-hidden="true"></i> Important Instructions</h2>
                        <p>Send the exact requested USDT amount to the address shown above. Keep your Deposit ID and transaction details for reference.</p>
                    </aside>
                    <aside class="deposit-info-card deposit-warning-card">
                        <h2><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> BEP20 Network Warning</h2>
                        <p>Use BEP20 USDT only. A transaction hash is a payment claim, not proof; an administrator must independently verify it during manual review before approval.</p>
                    </aside>
                    @if ($deposit->recipient_name_snapshot || $deposit->instructions_snapshot)
                        <aside class="deposit-info-card deposit-admin-instructions">
                            <h2><i class="bi bi-card-text" aria-hidden="true"></i> Payment Instructions</h2>
                            @if ($deposit->recipient_name_snapshot)<p><strong>Recipient:</strong> {{ $deposit->recipient_name_snapshot }}</p>@endif
                            @if ($deposit->instructions_snapshot)<p>{{ str_replace('BNB Smart Chain', 'BEP20', $deposit->instructions_snapshot) }}</p>@endif
                        </aside>
                    @endif
                </div>

                @if ($deposit->reviewed_at)
                    <p class="deposit-reviewed-at">Reviewed {{ $deposit->reviewed_at->timezone(config('app.timezone'))->format('M j, Y · H:i:s') }}@if ($deposit->rejection_reason) · {{ $deposit->rejection_reason }}@endif</p>
                @endif

                <a class="deposit-history-button" href="{{ route('wallet') }}#deposit-history"><i class="bi bi-clock-history" aria-hidden="true"></i> Back to Deposit History</a>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'wallet', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    <script>
        document.querySelectorAll('[data-deposit-submit]').forEach((form) => {
            form.addEventListener('submit', () => {
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.querySelector('[data-submit-label]').textContent = 'Submitting…';
                }
            });
        });

        document.querySelectorAll('[data-copy-value]').forEach((button) => {
            button.addEventListener('click', async () => {
                const feedback = document.getElementById(button.dataset.copyFeedbackTarget);
                if (!feedback) return;

                try {
                    await navigator.clipboard.writeText(button.dataset.copyValue);
                    feedback.textContent = 'Copied';
                } catch (error) {
                    feedback.textContent = 'Copy unavailable. Select and copy the value manually.';
                }
            });
        });
    </script>
@endsection
