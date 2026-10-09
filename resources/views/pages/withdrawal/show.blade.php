@extends('layouts.app')

@section('title', 'Quick PayMoney | Withdrawal Details')

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
        <main class="content-area app-shell profile-dashboard wallet-area wallet-withdrawal-area">
            <h1 class="wallet-title">Withdrawal Details</h1>
            <section class="portal-card wallet-history-card wallet-withdrawal-card" aria-label="Withdrawal transaction summary">
                <div class="wallet-history-main wallet-withdrawal-details">
                    <div><span class="wallet-history-label">Reference ID</span><strong>{{ $withdrawal->request_reference }}</strong></div>
                    <div><span class="wallet-history-label">Amount (INR)</span><strong>{{ \App\Support\Money::formatInr((string) $withdrawal->amount) }}</strong></div>
                    <div><span class="wallet-history-label">Status</span><strong><span class="portal-badge {{ $withdrawal->status }}">{{ ucfirst($withdrawal->status) }}</span></strong></div>
                    <div><span class="wallet-history-label">Requested Date</span><strong>{{ $withdrawal->requested_at->format('M j, Y · H:i') }}</strong></div>
                    <div><span class="wallet-history-label">Payout Method</span><strong>{{ $withdrawal->payout_method === 'bank' ? 'Bank Account' : 'Cash' }}</strong></div>
                    @if ($withdrawal->payout_method === 'bank')
                        <div><span class="wallet-history-label">Bank</span><strong>{{ $withdrawal->bank_name }}</strong></div>
                        <div><span class="wallet-history-label">Account Holder</span><strong>{{ $withdrawal->bank_account_holder }}</strong></div>
                        <div><span class="wallet-history-label">Account</span><strong>{{ str_repeat('•', max(0, mb_strlen((string) $withdrawal->bank_account_number) - 4)).substr((string) $withdrawal->bank_account_number, -4) }}</strong></div>
                        <div><span class="wallet-history-label">IFSC</span><strong>{{ $withdrawal->bank_ifsc_code }}</strong></div>
                    @endif
                    @if ($withdrawal->status === 'completed' && $withdrawal->completed_at)
                        <div><span class="wallet-history-label">Completed Date</span><strong>{{ $withdrawal->completed_at->format('M j, Y · H:i') }}</strong></div>
                    @endif
                    @if ($withdrawal->transaction_reference)
                        <div><span class="wallet-history-label">Payout reference</span><strong>{{ $withdrawal->transaction_reference }}</strong></div>
                    @endif
                    @if ($withdrawal->status === 'rejected' && $withdrawal->rejection_reason)
                        <div class="wallet-history-rejection"><span class="wallet-history-label">Reason</span><strong>{{ $withdrawal->rejection_reason }}</strong></div>
                    @endif
                </div>
                @if ($withdrawal->status === 'pending')
                    <p class="wallet-inline-notice">Your withdrawal request is pending admin review. The requested amount is reserved from your available INR balance.</p>
                @elseif ($withdrawal->status === 'processing')
                    <p class="wallet-inline-notice">Your withdrawal is being processed by an administrator.</p>
                @elseif ($withdrawal->status === 'rejected')
                    <p class="wallet-inline-notice">Your withdrawal was rejected and the reserved INR has been released back to your balance.</p>
                @elseif ($withdrawal->status === 'completed')
                    <p class="wallet-inline-notice">Your withdrawal is completed. The system records the administrator-confirmed payout.</p>
                @endif
                <div class="wallet-withdrawal-actions">
                    @if ($withdrawal->status === 'completed')
                        <a class="profile-action-button profile-sell-action wallet-back-link" href="{{ route('wallet.withdrawals.invoice', $withdrawal) }}"><i class="bi bi-receipt" aria-hidden="true"></i><span>Download Invoice</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    @endif
                    <a class="profile-action-button profile-bank-action wallet-back-link" href="{{ route('wallet') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Back to My Wallet</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'wallet', 'variant' => 'wallet', 'appShell' => true])
    </div>
@endsection
