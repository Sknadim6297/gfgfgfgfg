@extends('layouts.app')

@section('title', 'Quick PayMoney | My Referrals')

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
                <header class="referral-dashboard-heading">
                    <div>
                        <h1>My Referrals</h1>
                        <p>Invite friends. Earn rewards as they qualify.</p>
                    </div>
                    <div class="referral-earned">
                        <span>Total Earned</span>
                        <strong>{{ \App\Support\Money::formatInr($totalEarned) }}</strong>
                    </div>
                </header>

                <ol class="referral-levels" aria-label="Referral reward levels">
                    @foreach ($levels as $level)
                        @php($credited = in_array((int) $level->level, $creditedLevels, true))
                        <li class="referral-level {{ $level->is_active ? 'is-active' : 'is-inactive' }}">
                            <span class="referral-level-marker" aria-label="{{ $credited ? 'Reward credited' : 'Level '.$level->level }}">
                                @if ($credited)
                                    <span aria-hidden="true">✓</span>
                                @else
                                    {{ $level->level }}
                                @endif
                            </span>
                            <strong>Level {{ $level->level }}</strong>
                            <span class="referral-level-amount">{{ \App\Support\Money::formatInr((string) $level->amount) }}</span>
                            <span class="referral-level-status">{{ $level->is_active ? 'Active' : 'Inactive' }}</span>
                        </li>
                    @endforeach
                </ol>

                <div class="referral-details">
                    <div class="referral-detail">
                        <span class="referral-detail-label">My Referral Code</span>
                        <strong class="referral-code-value">{{ $user->referral_code }}</strong>
                    </div>
                    <div class="referral-detail">
                        <label class="referral-detail-label" for="referral-link">Referral Link</label>
                        <div class="referral-link-field">
                            <input class="profile-form-control" id="referral-link" value="{{ route('register', ['ref' => $user->referral_code]) }}" readonly>
                            <button class="referral-copy-button" type="button" data-copy-value="{{ route('register', ['ref' => $user->referral_code]) }}">
                                <i class="bi bi-copy" aria-hidden="true"></i>
                                <span>Copy Link</span>
                                <span data-copy-feedback aria-live="polite"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <a class="referral-history-link" href="{{ route('profile.referrals.history') }}">View Referral History</a>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
