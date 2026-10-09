@extends('layouts.app')

@section('title', 'Quick PayMoney | Referrals')

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
            <a class="profile-back-link" href="{{ route('profile') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Profile</a>
            <section class="profile-content-card">
                <div class="profile-content-heading">
                    <span class="profile-content-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                    <div><h2>Referrals</h2><p>Invite customers and track referral rewards.</p></div>
                </div>
                <div class="profile-referral-stats">
                    <article><span>Total Direct Referrals</span><strong>{{ number_format($referralCount) }}</strong></article>
                    <article><span>Qualified Referrals</span><strong>{{ number_format($qualifiedReferralCount) }}</strong></article>
                    <article><span>Total Earned</span><strong>{{ \App\Support\Money::formatInr($totalEarned) }}</strong></article>
                    @foreach ([1, 2, 3] as $level)
                        <article><span>Level {{ $level }} Earnings</span><strong>{{ \App\Support\Money::formatInr($commissionTotals[(string) $level]) }}</strong></article>
                    @endforeach
                </div>
                <div class="profile-referral-field">
                    <label for="referral-code">My Referral Code</label>
                    <div class="profile-copy-field"><input class="profile-form-control" id="referral-code" value="{{ $user->referral_code }}" readonly><button type="button" data-copy-value="{{ $user->referral_code }}" aria-label="Copy referral code"><i class="bi bi-copy" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button></div>
                </div>
                <div class="profile-referral-field">
                    <label for="referral-link">Referral Link</label>
                    <div class="profile-copy-field"><input class="profile-form-control" id="referral-link" value="{{ route('register', ['ref' => $user->referral_code]) }}" readonly><button type="button" data-copy-value="{{ route('register', ['ref' => $user->referral_code]) }}" aria-label="Copy referral link"><i class="bi bi-share" aria-hidden="true"></i><span data-copy-feedback aria-live="polite"></span></button></div>
                </div>
                <div class="wallet-section-heading">
                    <h3>Referral qualification progress</h3>
                    <a class="wallet-details-link" href="{{ route('profile.referrals.history') }}">Full referral history</a>
                </div>
                <p class="wallet-history-note">A referred customer qualifies after cumulative completed withdrawals reach 500 USDT equivalent, using the Base Rate snapshots saved with each withdrawal.</p>
                @forelse ($referrals as $referral)
                    @php($progress = $referralProgress[$referral->id])
                    <article class="wallet-history-item">
                        <div class="wallet-history-main">
                            <div><span class="wallet-history-label">Customer reference</span><strong>{{ $referral->customer_id }}</strong></div>
                            <div><span class="wallet-history-label">Status</span><strong>{{ $progress['qualified'] ? 'Qualified' : 'Not qualified' }}</strong></div>
                            <div><span class="wallet-history-label">Completed withdrawals</span><strong>{{ \App\Support\Money::formatUsdt($progress['eligible_total']) }} USDT</strong></div>
                            <div><span class="wallet-history-label">{{ $progress['qualified'] ? 'Qualified date' : 'Remaining to qualify' }}</span><strong>{{ $progress['qualified'] ? $referral->referralQualification->qualified_at->timezone(config('app.timezone'))->format('M j, Y · H:i') : \App\Support\Money::formatUsdt($progress['remaining']).' USDT' }}</strong></div>
                        </div>
                        @if (! $progress['qualified'] && $estimatedLevelOneReward !== null)
                            <p class="wallet-history-note">Estimated reward — not earned or payable until qualification. Potential Level 1 reward: {{ \App\Support\Money::formatInr((string) $estimatedLevelOneReward) }}.</p>
                        @endif
                    </article>
                @empty
                    <p class="wallet-empty">No referrals yet. Customers who register through your link will appear here.</p>
                @endforelse
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
