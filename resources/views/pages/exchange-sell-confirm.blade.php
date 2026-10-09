@extends('layouts.app')

@section('title', 'Quick PayMoney | Confirm Sell Request')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/sell-exchange.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area profile-dashboard profile-subpage">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('exchange.sell') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Sell USDT</a>
            <section class="profile-content-card sell-request-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span><div><h2>Confirm Sell Request</h2><p>Review the sell amount and applicable rate before submitting.</p></div></div>
                <div class="sell-confirmation-title">SELL SUMMARY</div>
                <dl class="sell-confirmation-list">
                    <div><dt>USDT to Sell</dt><dd>{{ \App\Support\Money::formatUsdt($quote['amount']) }} USDT</dd></div>
                    <div><dt>Applied Rate</dt><dd>₹{{ \App\Models\ExchangeRate::formatDecimal($quote['rate']) }} / USDT <small>{{ $quote['rate_plan_name'] }}</small></dd></div>
                    <div class="sell-confirmation-total"><dt>INR After Approval</dt><dd>{{ \App\Support\Money::formatInr($quote['inr_amount']) }}</dd></div>
                    <div><dt>Status after submission</dt><dd><span class="portal-badge pending">Pending Review</span></dd></div>
                </dl>
                <p class="sell-accounting-note">INR will be credited to your INR Wallet after admin approval.</p>
                <form class="sell-confirmation-actions" method="POST" action="{{ route('exchange.requests.store') }}">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
                    <input type="hidden" name="quote_token" value="{{ $quote['quote_token'] }}">
                    <input type="hidden" name="amount" value="{{ $quote['amount'] }}">
                @if ($hasWalletPin)
                    <div class="wallet-pin-field">
                        <label id="sell-transaction-pin-label">Wallet Transaction PIN</label>
                        <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="sell-transaction-pin-label" data-pin-input-group>
                            @for ($digit = 1; $digit <= 4; $digit++)
                                <input class="profile-form-control wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="Wallet Transaction PIN digit {{ $digit }} of 4" data-pin-digit>
                            @endfor
                        </div>
                        <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                        <input type="hidden" name="wallet_transaction_pin" data-pin-value data-pin-required-input>
                    </div>
                    @error('wallet_transaction_pin')<span class="profile-field-error">{{ $message }}</span>@enderror
                @else
                    <div data-wallet-pin-entry hidden class="wallet-pin-field">
                        <label id="sell-transaction-pin-label">Wallet Transaction PIN</label>
                        <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="sell-transaction-pin-label" data-pin-input-group>
                            @for ($digit = 1; $digit <= 4; $digit++)
                                <input class="profile-form-control wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="Wallet Transaction PIN digit {{ $digit }} of 4" data-pin-digit>
                            @endfor
                        </div>
                        <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                        <input type="hidden" name="wallet_transaction_pin" data-pin-value data-pin-required-input>
                    </div>
                    <p class="sell-accounting-note">Wallet Transaction PIN Required: set a PIN before selling USDT.</p>
                    <button class="sell-cancel-button sell-pin-action" type="button" data-wallet-pin-open data-wallet-pin-purpose="wallet_password_set">Set Wallet Transaction PIN</button>
                @endif
                    <button class="sell-submit-button" type="submit" data-pin-required-submit @disabled(! $hasWalletPin)>Confirm Sell</button>
                    <a class="sell-cancel-button" href="{{ route('exchange.sell') }}">Cancel</a>
                </form>
            </section>
            <p class="sell-accounting-note" role="status" data-pin-success-message hidden></p>
        </main>
        @include('partials.wallet-pin-modal', ['user' => $user, 'maskedEmail' => $maskedEmail, 'autoOpenWalletPin' => ! $hasWalletPin])
        @include('partials.bottom-nav', ['active' => 'exchange', 'variant' => 'standard'])
    </div>
@endsection
