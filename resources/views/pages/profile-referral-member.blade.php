@extends('layouts.app')

@section('title', 'Quick PayMoney | Referral Member')

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
        <main class="content-area profile-dashboard profile-subpage referral-dashboard">
            <section class="profile-content-card referral-dashboard-card">
                <a class="referral-member-link" href="{{ route('profile.referrals') }}">← Back to referral dashboard</a>
                <header class="referral-dashboard-heading">
                    <div>
                        <span class="referral-eyebrow">REFERRED MEMBER · CUSTOMER {{ $maskedCustomerId }}</span>
                        <h1>{{ $referral->name }}</h1>
                        <p>Joined {{ $referral->created_at->timezone(config('app.timezone'))->format('M j, Y') }} · Private referral activity visible only to you.</p>
                    </div>
                    <span class="portal-badge {{ $qualified ? 'completed' : 'pending' }}">{{ $qualified ? 'Qualified' : 'In progress' }}</span>
                </header>

                <section class="referral-overview" aria-label="Referral member overview">
                    <article class="referral-metric">
                        <span>Completed sell volume</span>
                        <strong>{{ \App\Support\Money::formatUsdt($totalSellVolume) }} USDT</strong>
                        <small>All recorded completed sell conversions</small>
                    </article>
                    <article class="referral-metric">
                        <span>Eligible qualification volume</span>
                        <strong>{{ \App\Support\Money::formatUsdt($eligibleSellVolume) }} USDT</strong>
                        <small>Only newly tracked, ledger-backed sell conversions</small>
                    </article>
                    <article class="referral-metric">
                        <span>Commission earned</span>
                        <strong>{{ \App\Support\Money::formatInr($commissionEarned) }}</strong>
                        <small>Credited to your INR wallet</small>
                    </article>
                    <article class="referral-metric">
                        <span>Latest completed sell</span>
                        <strong>{{ $latestSellAt?->timezone(config('app.timezone'))->format('M j, Y') ?? '—' }}</strong>
                        <small>{{ $qualified ? 'Qualified '.$qualifiedAt?->timezone(config('app.timezone'))->format('M j, Y') : \App\Support\Money::formatUsdt($remaining).' USDT remaining' }}</small>
                    </article>
                </section>

                <section class="referral-member-progress" aria-label="Qualification progress">
                    @if ($legacyWithdrawalQualification)
                        <p class="referral-progress-note">This member qualified under the previous completed-withdrawal rule. That historical qualification and its commissions are preserved; no sell history has been credited retroactively.</p>
                    @else
                        <div class="referral-progress-values">
                            <div><span>Qualification progress</span><strong>{{ \App\Support\Money::formatUsdt($eligibleSellVolume) }} / {{ \App\Support\Money::formatUsdt($threshold) }} USDT</strong></div>
                            <div><span>Remaining</span><strong>{{ \App\Support\Money::formatUsdt($remaining) }} USDT</strong></div>
                        </div>
                        <progress class="referral-progress-bar" max="500" value="{{ \App\Support\Decimal::compare($eligibleSellVolume, $threshold, 8) >= 0 ? '500' : $eligibleSellVolume }}" aria-label="Referral qualification progress">{{ $eligibleSellVolume }} / 500 USDT</progress>
                    @endif
                    @if ($qualified && ! $legacyWithdrawalQualification)
                        <p class="referral-progress-note">Qualified on {{ $qualifiedAt?->timezone(config('app.timezone'))->format('M j, Y · H:i') }}. Qualification is immutable.</p>
                    @elseif (! $qualified)
                        <p class="referral-progress-note">Earlier completed sells are displayed in history but are not used for retroactive commission qualification.</p>
                    @endif
                </section>
            </section>

            <section class="profile-content-card referral-history-panel">
                <header class="referral-section-heading">
                    <div><span class="referral-eyebrow">CONVERSION ACTIVITY</span><h2>Completed sell history</h2><p>USDT amount, saved conversion rate, INR credit, and eligibility are shown for each completed sell.</p></div>
                </header>
                @forelse ($sells as $sell)
                    <article class="referral-commission-card referral-sell-card">
                        <div class="referral-commission-heading">
                            <div><span class="referral-eyebrow">{{ $sell->request_reference }}</span><strong>{{ $sell->completed_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</strong></div>
                            <span class="portal-badge completed">Completed</span>
                        </div>
                        <dl class="referral-commission-details">
                            <div><dt>USDT sold</dt><dd>{{ \App\Support\Money::formatUsdt((string) $sell->usdt_amount) }} USDT</dd></div>
                            <div><dt>Saved rate</dt><dd>₹{{ $sell->exchange_rate }} / USDT</dd></div>
                            <div><dt>INR credited</dt><dd>{{ \App\Support\Money::formatInr((string) $sell->inr_amount) }}</dd></div>
                            <div><dt>Referral eligibility</dt><dd>{{ $sell->referral_eligible_at ? 'Eligible' : 'Historical — excluded from retroactive qualification' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <div class="referral-empty-state referral-empty-state-compact"><strong>No completed sells yet</strong><p>Completed sell conversions will appear here when this member has activity.</p></div>
                @endforelse
                @if ($sells->hasPages())<div class="wallet-pagination">{{ $sells->links() }}</div>@endif
            </section>

            <section class="profile-content-card referral-history-panel">
                <header class="referral-section-heading">
                    <div><span class="referral-eyebrow">YOUR WALLET CREDITS</span><h2>Commission history from this member</h2><p>Each entry includes its configured level, qualification date, and ledger credit reference.</p></div>
                </header>
                @forelse ($commissions as $commission)
                    <article class="referral-commission-card">
                        <div class="referral-commission-heading"><div><span class="referral-eyebrow">LEVEL {{ $commission->level }}</span><strong>{{ \App\Support\Money::formatInr((string) $commission->amount) }}</strong></div><span class="portal-badge {{ $commission->status }}">{{ ucfirst($commission->status) }}</span></div>
                        <dl class="referral-commission-details">
                            <div><dt>Qualification date</dt><dd>{{ $commission->qualified_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</dd></div>
                            <div><dt>Qualified amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) ($commission->qualification?->cumulative_usdt_equivalent ?? '0')) }} USDT</dd></div>
                            <div><dt>Transaction reference</dt><dd>{{ $commission->triggeringExchangeRequest?->request_reference ?? $commission->triggeringWithdrawal?->request_reference ?? '—' }}</dd></div>
                            <div><dt>Wallet ledger</dt><dd>{{ $commission->ledger_entry_id ? 'INR ledger #'.$commission->ledger_entry_id : 'Not credited' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <div class="referral-empty-state referral-empty-state-compact"><strong>No commission credits from this member</strong><p>When this referral qualifies under the configured rules, commission credits will be listed here.</p></div>
                @endforelse
                @if ($commissions->hasPages())<div class="wallet-pagination">{{ $commissions->links() }}</div>@endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection
