@extends('layouts.app')

@section('title', 'Quick PayMoney | Sell USDT')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/sell-exchange.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area profile-dashboard profile-subpage">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('exchange') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Exchange</a>
            <section class="profile-content-card sell-request-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span> <div><h2>Sell USDT</h2></div></div>
                <div class="sell-balance-panel">
                    <span>Available USD Balance</span>
                    <strong>{{ \App\Support\Money::formatUsd($availableBalance) }}</strong>
                </div>
                <form class="sell-request-form" method="POST" action="{{ route('exchange.requests.quote') }}" data-sell-quote-url="{{ route('exchange.sell.estimate') }}">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ old('submission_key', $submissionKey) }}">
                    <label for="sell-amount">Sell Amount</label>
                    <input class="profile-form-control" id="sell-amount" name="amount" type="number" min="0.00000001" max="999999999999.99999999" step="0.00000001" inputmode="decimal" autocomplete="off" value="{{ old('amount') }}" placeholder="Enter amount" @if ($errors->has('amount')) aria-invalid="true" aria-describedby="sell-amount-error" @endif required>
                    @error('amount')<span class="profile-field-error" id="sell-amount-error">{{ $message }}</span>@enderror
                    <div class="sell-estimate" id="sell-estimate" aria-live="polite" hidden>
                        <div><span>Applicable Rate</span><strong id="sell-estimate-plan">—</strong></div>
                        <div><span>Rate</span><strong id="sell-estimate-rate">—</strong></div>
                        <div><span>You Will Receive</span><strong id="sell-estimate-inr">—</strong></div>
                    </div>
                    <p class="sell-estimate-error" id="sell-estimate-error" role="status" hidden></p>

                    <button class="sell-submit-button" type="submit"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Sell USDT</button>
                </form>
                <p class="sell-accounting-note">Your available amount is based on your recorded USD balance. Review and confirm the displayed rate to convert USDT directly to your INR Wallet.</p>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'exchange', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    <script>
        (() => {
            const form = document.querySelector('.sell-request-form');
            const amount = document.getElementById('sell-amount');
            const estimate = document.getElementById('sell-estimate');
            const error = document.getElementById('sell-estimate-error');
            if (!form || !amount || !estimate || !error) return;

            let timer;
            let controller;
            let sequence = 0;
            const clear = () => {
                estimate.hidden = true;
                error.hidden = true;
                error.textContent = '';
            };

            amount.addEventListener('input', () => {
                clearTimeout(timer);
                controller?.abort();
                clear();
                if (!amount.value) return;

                timer = setTimeout(async () => {
                    const current = ++sequence;
                    controller = new AbortController();
                    const url = new URL(form.dataset.sellQuoteUrl, window.location.href);
                    url.searchParams.set('amount', amount.value);
                    try {
                        const response = await fetch(url, {
                            headers: { Accept: 'application/json' },
                            signal: controller.signal,
                        });
                        const data = await response.json();
                        if (current !== sequence) return;
                        if (!response.ok) throw new Error(data.errors?.amount?.[0] ?? 'Unable to calculate this estimate.');

                        document.getElementById('sell-estimate-plan').textContent = data.plan;
                        document.getElementById('sell-estimate-rate').textContent = `₹${data.rate} / USDT`;
                        const [whole, fraction] = data.estimated_inr.split('.');
                        document.getElementById('sell-estimate-inr').textContent =
                            `₹${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction ?? '00'}`;
                        estimate.hidden = false;
                    } catch (exception) {
                        if (exception.name === 'AbortError' || current !== sequence) return;
                        error.textContent = exception.message;
                        error.hidden = false;
                    }
                }, 160);
            });
        })();
    </script>
@endsection
