@extends('layouts.app')

@section('title', 'Quick PayMoney | Referral History')

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
            <a class="profile-back-link" href="{{ route('profile.referrals') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Referrals</a>

            <section class="profile-content-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><h2>Referral Qualification Progress</h2><p>Only completed withdrawals with a valid saved Base Rate count toward the 500 USDT threshold.</p></div></div>
                @forelse ($referrals as $referral)
                    @php($progress = $referralProgress[$referral->id])
                    <article class="wallet-history-item">
                        <div class="wallet-history-main">
                            <div><span class="wallet-history-label">Customer reference</span><strong>{{ $referral->customer_id }}</strong></div>
                            <div><span class="wallet-history-label">Qualification status</span><strong><span class="portal-badge {{ $progress['qualified'] ? 'completed' : 'pending' }}">{{ $progress['qualified'] ? 'Qualified' : 'Not qualified' }}</span></strong></div>
                            <div><span class="wallet-history-label">Eligible completed withdrawals</span><strong>{{ \App\Support\Money::formatUsdt($progress['eligible_total']) }} USDT</strong></div>
                            <div><span class="wallet-history-label">{{ $progress['qualified'] ? 'Qualified date' : 'Remaining' }}</span><strong>{{ $progress['qualified'] ? $referral->referralQualification->qualified_at->timezone(config('app.timezone'))->format('M j, Y · H:i') : \App\Support\Money::formatUsdt($progress['remaining']).' USDT' }}</strong></div>
                        </div>
                        @if (! $progress['qualified'] && $estimatedLevelOneReward !== null)
                            <p class="wallet-history-note">Estimated reward — not earned or payable until qualification. Potential Level 1 reward: {{ \App\Support\Money::formatInr((string) $estimatedLevelOneReward) }}.</p>
                        @endif
                    </article>
                @empty
                    <p class="wallet-empty">No referrals yet. Customers who register through your link will appear here.</p>
                @endforelse
                @if ($referrals->hasPages())
                    <div class="wallet-pagination">{{ $referrals->links() }}</div>
                @endif
            </section>

            <section class="profile-content-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span><div><h2>Commission History</h2><p>Recorded commission status and credit details from your referral activity.</p></div></div>
                @forelse ($commissions as $commission)
                    <article class="wallet-history-item">
                        <div class="wallet-history-main">
                            <div><span class="wallet-history-label">Referral level</span><strong>Level {{ $commission->level }}</strong></div>
                            <div><span class="wallet-history-label">Qualified customer reference</span><strong>{{ $commission->qualifiedUser?->customer_id ?? 'Referral customer' }}</strong></div>
                            <div><span class="wallet-history-label">Qualification status</span><strong>Qualified · {{ $commission->qualified_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</strong></div>
                            <div><span class="wallet-history-label">Commission amount</span><strong>{{ \App\Support\Money::formatInr((string) $commission->amount) }}</strong></div>
                            <div><span class="wallet-history-label">Credited date</span><strong>{{ $commission->credited_at?->timezone(config('app.timezone'))->format('M j, Y · H:i') ?? 'Not credited' }}</strong></div>
                            <div><span class="wallet-history-label">Transaction status</span><strong><span class="portal-badge {{ $commission->status }}">{{ ucfirst($commission->status) }}</span></strong></div>
                        </div>
                    </article>
                @empty
                    <p class="wallet-empty">No referral commissions have been recorded yet.</p>
                @endforelse
                @if ($commissions->hasPages())
                    <div class="wallet-pagination">{{ $commissions->links() }}</div>
                @endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
