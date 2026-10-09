@extends('layouts.app')

@section('title', 'Quick PayMoney | Withdraw INR')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/wallet.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area app-shell profile-dashboard wallet-area">
            <a class="profile-back-link" href="{{ route('wallet') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to My Wallet</a>
            <h1 class="wallet-title">Withdraw INR</h1>
            <section class="profile-balance-panel wallet-balances" aria-label="Available INR balance">
                <article class="profile-balance-card wallet-balance-card wallet-inr-card">
                    <span class="profile-balance-icon"><i class="bi bi-currency-rupee" aria-hidden="true"></i></span>
                    <span class="profile-balance-label">Available INR Balance</span>
                    <strong>{{ \App\Support\Money::formatInr((string) $user->inr_balance) }}</strong>
                </article>
            </section>

            <section class="portal-card withdrawal-form-card">
                <div class="wallet-section-heading"><h2><i class="bi bi-arrow-up-right-circle" aria-hidden="true"></i> Withdrawal Request</h2></div>
                @if (\App\Support\Decimal::compare((string) $user->inr_balance, '0', 2) <= 0)
                    <p class="wallet-inline-notice">No INR balance is currently available for withdrawal.</p>
                @else
                    <form class="wallet-withdrawal-form" method="POST" action="{{ route('wallet.withdrawals.store') }}">
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
                        <label for="withdrawal-amount">Withdrawal Amount (INR)</label>
                        <input class="portal-input" id="withdrawal-amount" name="amount" type="number" min="0.01" max="{{ $user->inr_balance }}" step="0.01" inputmode="decimal" value="{{ old('amount') }}" required>
                        @error('amount')<span class="wallet-field-error">{{ $message }}</span>@enderror

                        @php
                            $selectedPayoutMethod = old('payout_method', 'cash');
                            if ($bankVerificationStatus !== 'verified') {
                                $selectedPayoutMethod = 'cash';
                            }
                            $bankStatusLabel = match ($bankVerificationStatus) {
                                'pending' => 'Pending verification',
                                'rejected' => 'Verification rejected',
                                default => 'Not added',
                            };
                        @endphp
                        <fieldset class="wallet-payout-options">
                            <legend>Payout Method</legend>
                            <label><input type="radio" name="payout_method" value="cash" @checked($selectedPayoutMethod === 'cash') required><span>Cash<small>No bank information required.</small></span></label>
                            <label class="{{ $bankVerificationStatus === 'verified' ? '' : 'is-disabled' }}">
                                <input type="radio" name="payout_method" value="bank" @checked($selectedPayoutMethod === 'bank') @disabled($bankVerificationStatus !== 'verified')>
                                <span>Bank Account @if ($bankVerificationStatus !== 'verified')<small>— {{ $bankStatusLabel }}</small>@endif</span>
                            </label>
                        </fieldset>
                        @error('payout_method')<span class="wallet-field-error">{{ $message }}</span>@enderror

                        @if ($bankVerificationStatus === 'verified')
                            <div class="wallet-bank-destination">
                                <i class="bi bi-bank" aria-hidden="true"></i>
                                <span>{{ $user->bank_name }} · {{ $user->account_holder_name }} · Account {{ str_repeat('•', max(0, mb_strlen((string) $user->account_number) - 4)).substr((string) $user->account_number, -4) }} · IFSC {{ $user->ifsc_code }}</span>
                            </div>
                        @endif
                        @error('bank')<span class="wallet-field-error">{{ $message }}</span>@enderror

                        @if ($hasWalletPin)
                            <div data-wallet-pin-entry class="wallet-pin-field">
                                <label id="withdrawal-transaction-pin-label">Wallet Transaction PIN</label>
                                <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="withdrawal-transaction-pin-label" data-pin-input-group>
                                    @for ($digit = 1; $digit <= 4; $digit++)
                                        <input class="portal-input wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="Wallet Transaction PIN digit {{ $digit }} of 4" data-pin-digit>
                                    @endfor
                                </div>
                                <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                                <input type="hidden" name="wallet_transaction_pin" data-pin-value data-pin-required-input>
                            </div>
                        @else
                            <div data-wallet-pin-entry hidden class="wallet-pin-field">
                                <label id="withdrawal-transaction-pin-label">Wallet Transaction PIN</label>
                                <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="withdrawal-transaction-pin-label" data-pin-input-group>
                                    @for ($digit = 1; $digit <= 4; $digit++)
                                        <input class="portal-input wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="Wallet Transaction PIN digit {{ $digit }} of 4" data-pin-digit>
                                    @endfor
                                </div>
                                <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                                <input type="hidden" name="wallet_transaction_pin" data-pin-value data-pin-required-input>
                            </div>
                            <p class="wallet-inline-notice">Set a Wallet Transaction PIN to confirm a withdrawal.</p>
                            <button class="profile-action-button profile-bank-action" type="button" data-wallet-pin-open data-wallet-pin-purpose="wallet_password_set">Set Wallet Transaction PIN</button>
                        @endif
                        @error('wallet_transaction_pin')<span class="wallet-field-error">{{ $message }}</span>@enderror

                        <button class="profile-action-button profile-sell-action wallet-withdraw-submit" type="submit" data-pin-required-submit @disabled(! $hasWalletPin)>
                            <i class="bi bi-arrow-up-right-circle" aria-hidden="true"></i><span>Confirm Withdrawal</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </button>
                    </form>
                @endif
                <a class="wallet-details-link withdrawal-history-link" href="{{ route('wallet') }}#withdrawal-history">View Withdrawal History</a>
            </section>
            <p class="portal-status" role="status" data-pin-success-message hidden></p>
        </main>
        @include('partials.wallet-pin-modal', ['user' => $user, 'maskedEmail' => $maskedEmail, 'autoOpenWalletPin' => ! $hasWalletPin && \App\Support\Decimal::compare((string) $user->inr_balance, '0', 2) > 0])
        @include('partials.bottom-nav', ['active' => 'wallet', 'variant' => 'wallet', 'appShell' => true])
    </div>
@endsection
