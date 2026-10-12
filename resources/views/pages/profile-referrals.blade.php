@extends('layouts.app')

@section('title', 'Quick PayMoney | Referral Dashboard')

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
    @php($referralLink = route('register', ['ref' => $user->referral_code]))
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area profile-dashboard profile-subpage referral-dashboard">
            <section class="profile-content-card referral-dashboard-card">
                <header class="referral-dashboard-heading">
                    <div>
                        <span class="referral-eyebrow">QUICK PAYMONEY REWARDS</span>
                        <h1>Referral dashboard</h1>
                        <p>Track your invitations, qualification progress, and credited rewards.</p>
                    </div>
                    <span class="referral-heading-mark"><i class="bi bi-people-fill" aria-hidden="true"></i> Your network</span>
                </header>

                <section class="referral-overview" aria-label="Referral overview">
                    <article class="referral-metric referral-metric-total">
                        <span class="referral-metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                        <span>Total referrals</span>
                        <strong>{{ number_format($totalReferrals) }}</strong>
                        <small>Registered with your referral code</small>
                    </article>
                    <article class="referral-metric referral-metric-qualified">
                        <span class="referral-metric-icon"><i class="bi bi-patch-check" aria-hidden="true"></i></span>
                        <span>Qualified</span>
                        <strong>{{ number_format($qualifiedReferrals) }}</strong>
                        <small>Reached the 500 USDT threshold</small>
                    </article>
                    <article class="referral-metric referral-metric-pending">
                        <span class="referral-metric-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                        <span>Pending qualification</span>
                        <strong>{{ number_format($pendingReferrals) }}</strong>
                        <small>Still progressing toward qualification</small>
                    </article>
                    <article class="referral-metric referral-metric-earned">
                        <span class="referral-metric-icon"><i class="bi bi-currency-rupee" aria-hidden="true"></i></span>
                        <span>Total commission earned</span>
                        <strong>{{ \App\Support\Money::formatInr($totalEarned) }}</strong>
                        <small>Credited to your INR wallet</small>
                    </article>
                </section>

                <section class="referral-link-panel" aria-labelledby="referral-link-title">
                    <div class="referral-section-heading">
                        <div>
                            <span class="referral-eyebrow">INVITE A FRIEND</span>
                            <h2 id="referral-link-title">Your referral link</h2>
                            <p>New members must register using this link for the referral to be attributed.</p>
                        </div>
                        <span class="referral-code-pill">Code {{ $user->referral_code }}</span>
                    </div>
                    <div class="referral-link-field">
                        <input class="profile-form-control" id="referral-link" value="{{ $referralLink }}" readonly aria-label="Your referral link">
                        <button class="referral-copy-button" type="button" data-copy-value="{{ $referralLink }}">
                            <i class="bi bi-copy" aria-hidden="true"></i>
                            <span>Copy link</span>
                            <span data-copy-feedback aria-live="polite"></span>
                        </button>
                        <a class="referral-share-button" href="https://wa.me/?text={{ urlencode('Join me on Quick PayMoney: '.$referralLink) }}" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>
                            <span>Share</span>
                        </a>
                    </div>
                </section>

                <section class="referral-rewards" aria-label="Configured referral rewards">
                    <span class="referral-eyebrow">CONFIGURED REWARDS</span>
                    <div class="referral-levels">
                        @foreach ($levels as $level)
                            @php($levelProgress = $referralLevels[(int) $level->level])
                            <article class="referral-level {{ $levelProgress['status'] === 'qualified' ? 'is-active' : ($levelProgress['status'] === 'locked' ? 'is-inactive' : 'is-unlocked') }}">
                                <span class="referral-level-marker" aria-label="{{ ucfirst($levelProgress['status']) }} level {{ $level->level }}">
                                    @if ($levelProgress['status'] === 'qualified')<span aria-hidden="true">✓</span>@else{{ $level->level }}@endif
                                </span>
                                <strong>Level {{ $level->level }}</strong>
                                <span class="referral-level-amount">{{ \App\Support\Money::formatInr((string) $level->amount) }}</span>
                                <span class="referral-level-status">{{ ucfirst($levelProgress['status']) }} · {{ $levelProgress['member_count'] }} {{ \Illuminate\Support\Str::plural('member', $levelProgress['member_count']) }}</span>
                            </article>
                        @endforeach
                    </div>
                    <p>Rewards are configured by Quick PayMoney and are credited only after the referred member qualifies.</p>
                </section>
            </section>

            <section class="profile-content-card referral-history-panel" id="qualification-progress">
                <header class="referral-section-heading">
                    <div>
                        <span class="referral-eyebrow">MEMBER ACTIVITY</span>
                        <h2>Qualification progress</h2>
                        <p>Completed USDT sells count toward the 500 USDT threshold. Older sells remain visible in history and are never credited retroactively.</p>
                    </div>
                    <span class="referral-threshold">Threshold · 500 USDT</span>
                </header>
                @forelse ($referrals as $referral)
                    @php($progress = $referralProgress[$referral->id])
                    <article class="referral-progress-card">
                        <div class="referral-progress-heading">
                            <div>
                                <span class="referral-eyebrow">REFERRED MEMBER</span>
                                <strong>{{ $referral->name }}</strong>
                                <span class="referral-member-id">Customer {{ $progress['masked_customer_id'] }}</span>
                                <small class="referral-joined-date"><i class="bi bi-calendar3" aria-hidden="true"></i> Joined {{ $referral->created_at->timezone(config('app.timezone'))->format('M j, Y') }} <span>·</span> Level 1</small>
                            </div>
                            <span class="portal-badge {{ $progress['qualified'] ? 'completed' : 'pending' }}">{{ $progress['qualified'] ? 'Qualified' : 'In progress' }}</span>
                        </div>
                        <div class="referral-progress-values">
                            <div><span>Completed sell volume</span><strong>{{ \App\Support\Money::formatUsdt($progress['total_sell_volume']) }} USDT</strong></div>
                            <div><span>Eligible toward qualification</span><strong>{{ \App\Support\Money::formatUsdt($progress['eligible_total']) }} USDT</strong></div>
                            <div><span>{{ $progress['qualified'] ? 'Qualified on' : 'Remaining' }}</span><strong>{{ $progress['qualified'] ? $referral->referralQualification->qualified_at->timezone(config('app.timezone'))->format('M j, Y') : \App\Support\Money::formatUsdt($progress['remaining']).' USDT' }}</strong></div>
                            <div><span>Latest completed sell</span><strong>{{ $progress['latest_sell_at']?->timezone(config('app.timezone'))->format('M j, Y') ?? '—' }}</strong></div>
                        </div>
                        @if ($progress['legacy_withdrawal_qualification'])
                            <p class="referral-progress-note">This referral was already qualified under the previous completed-withdrawal rule. Existing commissions are preserved; no historical sells have been applied retroactively.</p>
                        @else
                            <progress class="referral-progress-bar" max="500" value="{{ \App\Support\Decimal::compare($progress['eligible_total'], '500.00000000', 8) >= 0 ? '500' : $progress['eligible_total'] }}" aria-label="Qualification progress for {{ $referral->name }}">{{ $progress['eligible_total'] }} / 500 USDT</progress>
                        @endif
                        <div class="referral-progress-footer">
                            <a class="referral-member-link" href="{{ route('profile.referrals.member', $referral) }}"><i class="bi bi-clock-history" aria-hidden="true"></i> View member activity <span aria-hidden="true">→</span></a>
                        @if (! $progress['qualified'] && $estimatedLevelOneReward !== null)
                            <p class="referral-progress-note referral-estimate"><i class="bi bi-info-circle" aria-hidden="true"></i> Estimated reward — not earned or payable until qualification. Potential Level 1 reward: <strong>{{ \App\Support\Money::formatInr((string) $estimatedLevelOneReward) }}</strong></p>
                        @endif
                        </div>
                    </article>
                @empty
                    <div class="referral-empty-state">
                        <span class="referral-empty-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                        <strong>No attributed referrals yet</strong>
                        <p>Only registrations completed with your referral link or code appear here. Copy or share your link above to invite a member.</p>
                        <button class="referral-empty-action" type="button" data-copy-value="{{ $referralLink }}">Copy referral link</button>
                    </div>
                @endforelse
                @if ($referrals->hasPages())
                    <div class="wallet-pagination">{{ $referrals->links() }}</div>
                @endif
            </section>

            <section class="profile-content-card referral-history-panel" id="commission-history">
                <header class="referral-section-heading">
                    <div>
                        <span class="referral-eyebrow">WALLET CREDITS</span>
                        <h2>Commission history</h2>
                        <p>Commission amounts and wallet ledger references recorded for your referrals.</p>
                    </div>
                </header>
                @forelse ($commissions as $commission)
                    <article class="referral-commission-card">
                        <div class="referral-commission-heading">
                            <div>
                                <span class="referral-eyebrow">LEVEL {{ $commission->level }} REWARD</span>
                                <strong>{{ $commission->qualifiedUser?->name ?? 'Referral member' }}</strong>
                            </div>
                            <div class="referral-commission-status">
                                <strong class="referral-commission-amount">{{ \App\Support\Money::formatInr((string) $commission->amount) }}</strong>
                                <span class="portal-badge {{ $commission->status }}">{{ ucfirst($commission->status) }}</span>
                            </div>
                        </div>
                        <dl class="referral-commission-details">
                            <div><dt>Qualification date</dt><dd>{{ $commission->qualified_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</dd></div>
                            <div><dt>Qualifying amount</dt><dd>{{ \App\Support\Money::formatUsdt((string) ($commission->qualification?->cumulative_usdt_equivalent ?? '0')) }} USDT</dd></div>
                            <div>
                                <dt>Credit details</dt>
                                <dd>
                                    @if ($commission->ledger_entry_id)
                                        INR ledger #{{ $commission->ledger_entry_id }}
                                    @else
                                        Not credited to wallet
                                    @endif
                                </dd>
                            </div>
                            <div><dt>Transaction reference</dt><dd>{{ $commission->triggeringExchangeRequest?->request_reference ?? $commission->triggeringWithdrawal?->request_reference ?? '—' }}</dd></div>
                            <div><dt>Credited on</dt><dd>{{ $commission->credited_at?->timezone(config('app.timezone'))->format('M j, Y · H:i') ?? 'Not credited' }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <div class="referral-empty-state referral-empty-state-compact">
                        <span class="referral-empty-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span>
                        <strong>No commission credits recorded</strong>
                        <p>Commission history appears here when an eligible referral qualifies and a configured reward is credited.</p>
                    </div>
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
