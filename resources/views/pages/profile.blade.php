@extends('layouts.app')

@section('title', 'Quick PayMoney | Profile')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')

        <main class="content-area app-shell profile-dashboard">
            @include('partials.profile-hero', ['user' => $user])

            <section class="profile-balance-panel" aria-label="Account balance and reward">
                <article class="profile-balance-card">
                    <span class="profile-balance-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                    <span class="profile-balance-label">Account Balance</span>
                    <strong>{{ \App\Support\Money::formatUsd((string) $user->balance) }}</strong>
                    <small>Internal recorded balance</small>
                </article>
                <article class="profile-balance-card profile-reward-card">
                    <span class="profile-balance-icon"><i class="bi bi-gift" aria-hidden="true"></i></span>
                    <span class="profile-balance-label">Total Reward</span>
                    <strong>{{ \App\Support\Money::formatUsd('0.00') }}</strong>
                </article>
            </section>
            <p class="profile-balance-disclaimer">Your recorded balance is not a custodial wallet or confirmation of USDT holdings.</p>

            <div class="profile-primary-actions">
                <a class="profile-action-button profile-bank-action" href="{{ route('profile.bank') }}">
                    <i class="bi bi-bank" aria-hidden="true"></i><span>Enter Bank Details</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
                <a class="profile-action-button profile-sell-action" href="{{ route('exchange.sell') }}">
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Sell Now</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
                <div data-fcm-controls
                    data-register-url="{{ route('profile.notifications.device-tokens.store', absolute: false) }}"
                    data-unregister-url="{{ route('profile.notifications.device-tokens.destroy', absolute: false) }}"
                    data-csrf-token="{{ csrf_token() }}"
                    data-firebase-api-key="{{ config('services.firebase.api_key') }}"
                    data-firebase-app-id="{{ config('services.firebase.app_id') }}"
                    data-firebase-project-id="{{ config('services.firebase.project_id') }}"
                    data-firebase-sender-id="{{ config('services.firebase.messaging_sender_id') }}"
                    data-firebase-vapid-key="{{ config('services.firebase.vapid_public_key') }}"
                    data-service-worker-url="/firebase-messaging/sw.js">
                    <button class="profile-action-button profile-bank-action" type="button" data-fcm-enable>
                        <i class="bi bi-bell" aria-hidden="true"></i><span>Enable Notifications</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                    <button class="profile-action-button profile-bank-action" type="button" data-fcm-disable hidden>
                        <i class="bi bi-bell-slash" aria-hidden="true"></i><span>Disable Notifications</span>
                    </button>
                    <p class="portal-status" role="status" data-fcm-status hidden></p>
                </div>
            </div>

            <nav class="profile-menu" aria-label="Profile menu">
                <a class="profile-menu-item" href="{{ route('profile.bank') }}">
                    <span class="profile-menu-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
                    <span class="profile-menu-label">Bank Details</span>
                    <span class="profile-bank-status is-{{ match ($bankVerificationStatus) {
                        'not_submitted' => 'not-added',
                        'pending' => 'pending',
                        'verified' => 'verified',
                        'rejected' => 'rejected',
                    } }}">{{ match ($bankVerificationStatus) {
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        default => 'Not Added',
                    } }}</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a class="profile-menu-item" href="{{ route('profile.exchanges') }}">
                    <span class="profile-menu-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><span class="profile-menu-label">Exchange History<small>Sell requests and their current status</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a class="profile-menu-item" href="#wallet-transaction-pin" data-wallet-pin-open data-wallet-pin-purpose="{{ $user->hasWalletTransactionPin() ? 'wallet_password_change' : 'wallet_password_set' }}">
                    <span class="profile-menu-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span>Wallet Transaction PIN</span><span class="profile-bank-status" data-pin-status>{{ $user->hasWalletTransactionPin() ? 'Active' : 'Not Set' }}</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a class="profile-menu-item" href="{{ route('profile.referrals') }}">
                    <span class="profile-menu-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span>Referrals</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a class="profile-menu-item" href="{{ route('profile.referrals.history') }}">
                    <span class="profile-menu-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span>Referrals History</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <a class="profile-menu-item" href="{{ route('profile.password') }}">
                    <span class="profile-menu-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><span>Reset Password</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </nav>
        </main>

        @include('partials.wallet-pin-modal', ['user' => $user, 'maskedEmail' => mb_substr($user->email, 0, 1).'***@'.substr(strstr($user->email, '@'), 1)])
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard', 'appShell' => true])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
