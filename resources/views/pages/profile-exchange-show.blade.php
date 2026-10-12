@extends('layouts.app')

@section('title', 'Quick PayMoney | Exchange Details')

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
        <main class="content-area profile-dashboard profile-subpage">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('profile.exchanges') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Exchange History</a>
            <section class="profile-content-card" id="sell-request-details">
                @if ($exchange->available_usd_before !== null)
                    <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><div><h2>SELL CONVERSION</h2><p>USDT to INR Wallet conversion</p></div><span class="portal-badge {{ $exchange->status }}">{{ ucfirst($exchange->status) }}</span></div>
                    <div class="sell-receive-summary">
                        <span>YOU WILL RECEIVE</span>
                        <strong>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</strong>
                        <small>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }} USDT sold</small>
                    </div>
                    <h3 class="sell-detail-section-title">Exchange Details</h3>
                    <dl class="profile-detail-list">
                        <div><dt>USDT Sold</dt><dd>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }} USDT</dd></div>
                        <div><dt>Applied Rate</dt><dd>₹{{ \App\Models\ExchangeRate::formatDecimal($exchange->exchange_rate) }} / USDT</dd></div>
                        <div><dt>INR Amount</dt><dd>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</dd></div>
                    </dl>
                    <h3 class="sell-detail-section-title">Request Details</h3>
                    <dl class="profile-detail-list">
                        <div><dt>Reference ID</dt><dd class="exchange-reference-copy"><span>{{ $exchange->request_reference }}</span><button type="button" class="profile-copy-button" data-copy-value="{{ $exchange->request_reference }}" aria-label="Copy reference ID"><i class="bi bi-copy" aria-hidden="true"></i><span class="profile-copy-feedback" data-copy-feedback aria-live="polite"></span></button></dd></div>
                        <div><dt>Submitted</dt><dd><time datetime="{{ $exchange->created_at->toIso8601String() }}">{{ $exchange->created_at->format('M j, Y · H:i') }}</time></dd></div>
                        @if ($exchange->completed_at)
                            <div><dt>Completed</dt><dd><time datetime="{{ $exchange->completed_at->toIso8601String() }}">{{ $exchange->completed_at->format('M j, Y · H:i') }}</time></dd></div>
                        @elseif ($exchange->approved_at)
                            <div><dt>Approved</dt><dd><time datetime="{{ $exchange->approved_at->toIso8601String() }}">{{ $exchange->approved_at->format('M j, Y · H:i') }}</time></dd></div>
                        @endif
                        <div><dt>Status</dt><dd><span class="portal-badge {{ $exchange->status }}">{{ ucfirst($exchange->status) }}</span></dd></div>
                        @if ($exchange->status === 'rejected' && $exchange->rejection_reason)
                            <div><dt>Reason</dt><dd>{{ $exchange->rejection_reason }}</dd></div>
                        @endif
                    </dl>
                    @if ($exchange->status === 'completed')
                        <p class="sell-accounting-note">Your USDT conversion is complete. {{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }} was credited to your INR Wallet at the confirmed rate.</p>
                    @elseif ($exchange->status === 'approved')
                        <p class="sell-accounting-note">Your sell request has been approved. {{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }} was credited to your INR Wallet.</p>
                    @elseif (in_array($exchange->status, ['pending', 'processing'], true))
                        <aside class="sell-pending-note" role="status">
                            <span class="sell-pending-note-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                            <div class="sell-pending-note-copy">
                                <strong>Your sell request is awaiting settlement</strong>
                                <p>Approval does not mean payment settlement is complete.</p>
                            </div>
                        </aside>
                    @elseif ($exchange->status === 'rejected')
                        <p class="sell-accounting-note">Your request was rejected. The USD reservation was released and no INR was credited.</p>
                    @else
                        <p class="sell-accounting-note">This request has been marked {{ $exchange->status }}. Contact support if you need help confirming the INR wallet credit.</p>
                    @endif
                    <nav class="exchange-history-actions sell-detail-actions" aria-label="Sell request actions">
                        <a class="profile-view-link" href="{{ route('profile.exchanges') }}">View Exchange History</a>
                        <a class="profile-view-link" href="{{ route('exchange') }}">Back to Exchange</a>
                    </nav>
                @else
                    <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><div><h2>Exchange Details</h2><p>Request reference {{ $exchange->request_reference ?: 'EXC-'.$exchange->id }}</p></div></div>
                    <dl class="profile-detail-list">
                        <div><dt>USDT Amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) }} USDT</dd></div>
                        <div><dt>Applied INR Rate</dt><dd>₹{{ \App\Models\ExchangeRate::formatDecimal($exchange->exchange_rate) }} per USDT</dd></div>
                        <div><dt>Recorded INR Amount</dt><dd>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</dd></div>
                        <div><dt>Status</dt><dd><span class="portal-badge {{ $exchange->status }}">{{ ucfirst($exchange->status) }}</span></dd></div>
                        @if ($exchange->transaction_reference)<div><dt>Verification reference</dt><dd>{{ $exchange->transaction_reference }}</dd></div>@endif
                        <div><dt>Submitted</dt><dd><time datetime="{{ $exchange->created_at->toIso8601String() }}">{{ $exchange->created_at->format('M j, Y · H:i') }}</time></dd></div>
                    </dl>
                    <p class="profile-balance-disclaimer">Amounts and rates shown are the historical values recorded with this request; they are not recalculated using current rates.</p>
                @endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
