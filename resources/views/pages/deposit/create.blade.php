@extends('layouts.app')

@section('title', 'Quick PayMoney | Add Deposit')

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

        <main class="deposit-page">
            <a class="deposit-back-link" href="{{ route('exchange') }}"><i class="bi bi-arrow-left"></i> Back to exchange</a>
            <header class="deposit-heading">
                <span class="deposit-eyebrow">ADD FUNDS</span>
                <h1>Submit a deposit request</h1>
                <p>Use the payment details below, then submit your amount and transaction reference for manual review.</p>
            </header>

            <div class="deposit-layout">
                <section class="deposit-panel" aria-labelledby="deposit-payment-details-title">
                    <div class="deposit-panel-heading">
                        <span class="deposit-panel-icon"><i class="bi bi-qr-code" aria-hidden="true"></i></span>
                        <div><h2 id="deposit-payment-details-title">Payment details</h2><p>Confirm the recipient and payment instructions before sending funds.</p></div>
                    </div>
                    @if ($qrImageAvailable)
                        <a class="deposit-qr-link" href="{{ route('deposit.payment-qr') }}" target="_blank" rel="noopener">
                            <img class="deposit-qr-image" src="{{ route('deposit.payment-qr') }}" alt="Payment QR code">
                        </a>
                        <p class="deposit-qr-caption">Scan to pay, or select the QR image to open it at full size.</p>
                    @endif
                    @if ($settings?->recipient_name)
                        <dl class="deposit-recipient"><dt>Recipient name</dt><dd>{{ $settings->recipient_name }}</dd></dl>
                    @endif
                    @unless ($paymentSettingsAvailable)
                        <div class="deposit-notice"><i class="bi bi-info-circle" aria-hidden="true"></i><p>Payment instructions are currently unavailable. Please try again later.</p></div>
                        @if ($settings?->instructions)
                            <div class="deposit-instructions"><strong>Payment instructions</strong><p>{{ $settings->instructions }}</p></div>
                        @endif
                    @endunless
                </section>

                <section class="deposit-panel" aria-labelledby="deposit-reference-title">
                    <div class="deposit-panel-heading">
                        <span class="deposit-panel-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                        <div><h2 id="deposit-reference-title">Submit payment reference</h2><p>Provide the amount and transaction reference from your payment receipt.</p></div>
                    </div>

                    @if ($paymentSettingsAvailable)
                        <form class="deposit-form" method="POST" action="{{ route('deposit.store') }}" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">

                            <label for="amount">Deposit amount (USD)</label>
                            <input class="portal-input" id="amount" name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="e.g. 50.00" value="{{ old('amount') }}" maxlength="15" @if ($errors->has('amount')) aria-invalid="true" aria-describedby="deposit-amount-error" @endif required>
                            @error('amount')<span class="deposit-field-error" id="deposit-amount-error">{{ $message }}</span>@enderror

                            <label for="transaction_reference">Transaction ID / UTR</label>
                            <input class="portal-input" id="transaction_reference" name="transaction_reference" type="text" autocomplete="off" placeholder="Enter payment transaction reference" value="{{ old('transaction_reference') }}" maxlength="150" @if ($errors->has('transaction_reference')) aria-invalid="true" aria-describedby="deposit-reference-error" @endif required>
                            @error('transaction_reference')<span class="deposit-field-error" id="deposit-reference-error">{{ $message }}</span>@enderror

                            <label for="payment_proof">Payment proof screenshot <span>(optional, PNG/JPG/WebP, up to 5 MB)</span></label>
                            <input class="portal-input deposit-file-input" id="payment_proof" name="payment_proof" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" @if ($errors->has('payment_proof')) aria-invalid="true" aria-describedby="deposit-proof-error" @endif>
                            @error('payment_proof')<span class="deposit-field-error" id="deposit-proof-error">{{ $message }}</span>@enderror

                            <div class="deposit-review-note"><i class="bi bi-shield-check" aria-hidden="true"></i><p>This request will remain <strong>Pending</strong> until an administrator verifies the transaction externally. Submitting does not add money to your recorded balance.</p></div>
                            @if ($settings?->instructions)
                                <div class="deposit-instructions"><strong>Payment instructions</strong><p>{{ $settings->instructions }}</p></div>
                            @endif
                            <button class="portal-button deposit-submit" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Submit Deposit</button>
                        </form>
                    @else
                        <p class="deposit-muted">The request form will be available after payment details are configured.</p>
                    @endif
                </section>
            </div>
        </main>

        @include('partials.bottom-nav', ['active' => 'exchange', 'variant' => 'standard'])
    </div>
@endsection
