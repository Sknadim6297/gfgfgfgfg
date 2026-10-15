@extends('layouts.app')

@section('title', 'Quick PayMoney | Deposit USDT')

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
        <main class="deposit-page deposit-create-page">
            <a class="deposit-back-link" href="{{ route('wallet') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to wallet</a>

            @if (session('status'))
                <div class="deposit-success-notice" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><p>{{ session('status') }}</p></div>
            @endif
            @if ($errors->has('payment'))
                <div class="deposit-notice" role="alert"><i class="bi bi-info-circle" aria-hidden="true"></i><p>{{ $errors->first('payment') }}</p></div>
            @endif

            <section class="deposit-first-card" aria-labelledby="deposit-title">
                <div class="deposit-brand-mark" aria-hidden="true"><i class="bi bi-wallet2"></i></div>
                <span class="deposit-eyebrow">SECURE BLOCKCHAIN DEPOSIT</span>
                <h1 id="deposit-title">Deposit USDT</h1>
                <p class="deposit-first-subtitle">Add USDT to your Quick PayMoney wallet securely.</p>

                <div class="deposit-available-balance">
                    <span>Available Balance</span>
                    <strong>{{ \App\Support\Money::formatUsdt($availableBalance) }} <small>USDT</small></strong>
                </div>

                <div class="deposit-field-section">
                    <span class="deposit-section-label">SELECT NETWORK</span>
                    <div class="deposit-network-choice" aria-current="true">
                        @if ($logoImageAvailable)
                            <img class="deposit-choice-logo" src="{{ route('deposit.network-logo') }}" alt="BEP20 network logo">
                        @else
                            <span class="deposit-choice-logo deposit-choice-fallback" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                        @endif
                        <span class="deposit-network-choice-copy"><strong>BEP20</strong><small>USDT network</small></span>
                        <i class="bi bi-check-circle-fill deposit-network-selected" aria-label="Selected network"></i>
                    </div>
                </div>

                @if ($settings?->recipient_name || $settings?->instructions)
                    <div class="deposit-first-instructions">
                        <strong>Payment instructions</strong>
                        @if ($settings?->recipient_name)<span>{{ $settings->recipient_name }}</span>@endif
                        @if ($settings?->instructions)<p>{{ str_replace('BNB Smart Chain', 'BEP20', $settings->instructions) }}</p>@endif
                    </div>
                @endif

                @if ($paymentSettingsAvailable)
                    <form class="deposit-first-form" method="POST" action="{{ route('deposit.store') }}" data-deposit-submit>
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
                        <label class="deposit-section-label" for="amount">DEPOSIT AMOUNT</label>
                        <div class="deposit-amount-control">
                            <input id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" value="{{ old('amount') }}" maxlength="21" aria-describedby="deposit-minimum-help @if ($errors->has('amount')) deposit-amount-error @endif" required>
                            <span>USDT</span>
                        </div>
                        <small class="deposit-field-hint" id="deposit-minimum-help">Minimum deposit: {{ \App\Support\Money::formatUsdt((string) $settings->minimum_amount) }} USDT · Up to 8 decimal places</small>
                        @error('amount')<span class="deposit-field-error" id="deposit-amount-error">{{ $message }}</span>@enderror
                        <div class="deposit-network-warning"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><p>Only send BEP20 USDT. Funds sent using another network or token may be permanently lost.</p></div>
                        <button class="deposit-gradient-button" type="submit"><span data-submit-label>Continue</span><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                    </form>
                @else
                    <div class="deposit-notice" role="status"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><p>BEP20 deposits are not available right now. Please contact support or try again later.</p></div>
                @endif

                <a class="deposit-first-history-link" href="{{ route('wallet') }}#deposit-history"><i class="bi bi-clock-history" aria-hidden="true"></i> Deposit History</a>
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
                    button.querySelector('[data-submit-label]').textContent = 'Creating request…';
                }
            });
        });
    </script>
@endsection
