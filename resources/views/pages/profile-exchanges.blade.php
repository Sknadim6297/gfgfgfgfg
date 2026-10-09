@extends('layouts.app')

@section('title', 'Quick PayMoney | Exchange History')

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
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><div><h2>Exchange History</h2><p>Your Sell requests and their saved rate and INR amount.</p></div></div>
                <form class="profile-filter-form" method="GET" action="{{ route('profile.exchanges') }}">
                    <label class="visually-hidden" for="exchange-search">Search reference ID</label>
                    <input class="profile-form-control" id="exchange-search" type="search" name="search" maxlength="120" value="{{ request('search') }}" placeholder="Search reference ID">
                    <label class="visually-hidden" for="exchange-status">Filter by status</label>
                    <select class="profile-form-control" id="exchange-status" name="status"><option value="">All</option>@foreach (\App\Models\ExchangeRequest::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
                    <button class="profile-filter-button" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
                    @if (request()->filled('search') || request()->filled('status'))<a class="profile-clear-filter" href="{{ route('profile.exchanges') }}">Clear</a>@endif
                </form>
                @if ($exchanges->isEmpty())
                    <div class="profile-empty-state">
                        <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                        <strong>{{ $hasFilters ? 'No matching exchange requests' : 'No exchange requests yet' }}</strong>
                        <p>{{ $hasFilters ? 'Try a different search or status filter.' : 'When you sell USDT, your requests will appear here.' }}</p>
                        @if ($hasFilters)
                            <a class="profile-clear-filter" href="{{ route('profile.exchanges') }}">Clear filters</a>
                        @else
                            <a class="profile-action-button profile-sell-action" href="{{ route('exchange.sell') }}"><i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Sell USDT</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        @endif
                    </div>
                @else
                    <div class="exchange-history-list">
                        @foreach ($exchanges as $exchange)
                            @php
                                $displayAmount = rtrim(rtrim((string) $exchange->usdt_amount, '0'), '.');
                                $displayRate = rtrim(rtrim((string) $exchange->exchange_rate, '0'), '.');
                            @endphp
                            <article class="exchange-history-card">
                                <div class="exchange-history-card-heading">
                                    <div><span class="wallet-history-label">Reference ID</span><strong>{{ $exchange->request_reference ?: ($exchange->transaction_reference ?: 'EXC-'.$exchange->id) }}</strong></div>
                                    <span class="portal-badge {{ in_array($exchange->status, ['approved', 'completed'], true) ? 'approved' : $exchange->status }}">{{ ucfirst($exchange->status) }}</span>
                                </div>
                                <dl class="exchange-history-values">
                                    <div><dt>USDT Sold</dt><dd>{{ $exchange->available_usd_before !== null ? \App\Support\Money::formatUsdt((string) $exchange->usdt_amount) : $displayAmount }} USDT</dd></div>
                                    <div><dt>Rate</dt><dd>₹{{ $displayRate }} / USDT</dd></div>
                                    <div><dt>INR Amount</dt><dd>{{ \App\Support\Money::formatInr((string) $exchange->inr_amount) }}</dd></div>
                                    <div><dt>Submitted</dt><dd><time datetime="{{ $exchange->created_at->toIso8601String() }}">{{ $exchange->created_at->format('M j, Y · H:i') }}</time></dd></div>
                                </dl>
                                <div class="exchange-history-actions">
                                    @if ($exchange->request_reference)
                                        <a class="profile-view-link" href="{{ route('profile.exchanges.show', $exchange) }}">View Details</a>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @if ($exchanges->hasPages())<div class="profile-pagination">{{ $exchanges->links() }}</div>@endif
                @endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
