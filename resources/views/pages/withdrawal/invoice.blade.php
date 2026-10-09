@extends('layouts.app')

@section('title', 'Quick PayMoney | Withdrawal Invoice')

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
        <main class="content-area app-shell profile-dashboard wallet-area withdrawal-invoice">
            <h1 class="wallet-title">Withdrawal Invoice / Receipt</h1>
            <section class="portal-card wallet-history-card">
                <div class="profile-content-heading">
                    <span class="profile-content-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                    <div><h2>Quick PayMoney</h2><p>Withdrawal Invoice / Receipt</p></div>
                    <span class="portal-badge completed">COMPLETED</span>
                </div>
                <dl class="profile-detail-list">
                    <div><dt>Reference ID</dt><dd>{{ $withdrawal->request_reference }}</dd></div>
                    <div><dt>Customer ID</dt><dd>{{ $user->customer_id }}</dd></div>
                    <div><dt>Customer Name</dt><dd>{{ $user->name }}</dd></div>
                    <div><dt>INR Amount</dt><dd>{{ \App\Support\Money::formatInr((string) $withdrawal->amount) }}</dd></div>
                    <div><dt>Payment Method</dt><dd>{{ $withdrawal->payout_method === 'bank' ? 'Bank Account' : 'Cash' }}</dd></div>
                    @if ($withdrawal->payout_method === 'bank')
                        <div><dt>Bank</dt><dd>{{ $withdrawal->bank_name }}</dd></div>
                        <div><dt>Account</dt><dd>{{ $withdrawal->bank_account_holder }} · {{ str_repeat('•', max(0, mb_strlen((string) $withdrawal->bank_account_number) - 4)).substr((string) $withdrawal->bank_account_number, -4) }}</dd></div>
                        <div><dt>IFSC</dt><dd>{{ $withdrawal->bank_ifsc_code }}</dd></div>
                    @endif
                    <div><dt>Requested</dt><dd><time datetime="{{ $withdrawal->requested_at->toIso8601String() }}">{{ $withdrawal->requested_at->format('M j, Y · H:i') }}</time></dd></div>
                    <div><dt>Completed Date</dt><dd><time datetime="{{ $withdrawal->completed_at->toIso8601String() }}">{{ $withdrawal->completed_at->format('M j, Y · H:i') }}</time></dd></div>
                    @if ($withdrawal->transaction_reference)
                        <div><dt>Payout Reference</dt><dd>{{ $withdrawal->transaction_reference }}</dd></div>
                    @endif
                    <div><dt>Status</dt><dd>COMPLETED</dd></div>
                </dl>
                <div class="withdrawal-invoice-actions">
                    <button class="wallet-filter-button" type="button" onclick="window.print()">Download / Print Invoice</button>
                    <a class="profile-action-button profile-bank-action wallet-back-link" href="{{ route('wallet.withdrawals.show', $withdrawal) }}"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Back to Withdrawal Details</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'wallet', 'variant' => 'wallet', 'appShell' => true])
    </div>
@endsection
