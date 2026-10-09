@extends('layouts.app')

@section('title', 'Quick PayMoney | Deposit Request')

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
        <main class="deposit-page deposit-result-page">
            <a class="deposit-back-link" href="{{ route('wallet') }}#deposit-history"><i class="bi bi-arrow-left"></i> Deposit history</a>
            <section class="deposit-panel">
                <div class="deposit-panel-heading">
                    <span class="deposit-panel-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                    <div><span class="deposit-eyebrow">REFERENCE ID</span><h1>{{ $deposit->deposit_id }}</h1><p>Submitted {{ $deposit->submitted_at->format('M j, Y · H:i') }}</p></div>
                    <span class="portal-badge {{ $deposit->status }}"><span class="admin-badge-dot"></span>{{ ucfirst($deposit->status) }}</span>
                </div>
                <dl class="deposit-details">
                    <div><dt>Amount</dt><dd>{{ \App\Support\Money::formatUsd($deposit->amount) }}</dd></div>
                    <div><dt>Transaction ID / UTR</dt><dd>{{ $deposit->transaction_reference }}</dd></div>
                    <div><dt>Review status</dt><dd>{{ ucfirst($deposit->status) }}</dd></div>
                    @if ($deposit->rejection_reason)<div><dt>Admin reason</dt><dd>{{ $deposit->rejection_reason }}</dd></div>@endif
                </dl>
                @if ($deposit->proof_path)
                    <a class="deposit-secondary-button" href="{{ route('deposits.proof', $deposit) }}" target="_blank" rel="noopener"><i class="bi bi-image" aria-hidden="true"></i> View submitted proof</a>
                @endif
                <div class="deposit-review-note"><i class="bi bi-info-circle" aria-hidden="true"></i><p>Submission is not confirmation of payment or approval. Only an approved, externally verified deposit is reflected in your internal recorded balance.</p></div>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection
