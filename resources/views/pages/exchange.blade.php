@extends('layouts.app')

@section('title', 'Quick PayMoney | Exchange')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
@endsection

@section('styles')
<link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/exchange.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="page-wrapper">


        @include('partials.home-header')

        <!-- =====================================
         CONTENT
    ====================================== -->

        <main class="content">


        <!-- HERO -->

        <section class="hero-card">


            <div class="hero-content">

                <div class="hero-small">
                    WELCOME TO
                </div>

                <h1 class="hero-title">
                    Quick Pay<span>Money</span>
                </h1>

                <div class="hero-subtitle">
                    Your Trusted USDT Exchange Platform
                </div>


                <div class="hero-features">

                    <div class="hero-feature">

                        <div>
                            <i class="bi bi-shield-fill"></i>

                            <strong>100% Safe</strong>
                        </div>

                        <small>
                            Your Funds, Our Priority
                        </small>

                    </div>


                    <div class="hero-feature">

                        <div>
                            <i class="bi bi-lightning-fill"></i>

                            <strong>Fast Transactions</strong>
                        </div>

                        <small>
                            Within Minutes
                        </small>

                    </div>


                    <div class="hero-feature">

                        <div>
                            <i class="bi bi-globe2"></i>

                            <strong>Global Access</strong>
                        </div>

                        <small>
                            Anytime, Anywhere
                        </small>

                    </div>

                </div>

            </div>


            <!-- COIN -->

            <div class="coin-area">

                <div class="coin-letter">
                    ₮
                </div>

            </div>


            <!-- BALANCE -->

            <div class="balance-box">

                <small>
                    BALANCE
                </small>

                @if (auth('web')->user()?->role === 'user')
                    <strong>{{ \App\Support\Money::formatUsd($recordedBalance) }}</strong>
                @else
                    <strong>Sign in to view</strong>
                @endif
            </div>

        </section>



        <!-- =====================================
             ACTIONS
        ====================================== -->

        <section class="action-grid">

            <div class="row g-0">


                <!-- DEPOSIT -->

                <div class="col-6 col-md-3">

                    <a class="action-card" href="{{ route('deposit.create') }}" aria-label="Deposit add funds">

                        <div class="action-icon">

                            <i class="bi bi-wallet-fill"></i>

                        </div>

                        <h3 class="action-title">
                            Deposit
                        </h3>

                        <div class="action-subtitle">
                            Add Funds
                        </div>

                        <i class="bi bi-chevron-right action-arrow"></i>
                    </a>

                </div>


                <!-- SELL -->

                <div class="col-6 col-md-3">

                    <a class="action-card" href="{{ auth('web')->user()?->role === 'user' ? route('exchange.sell') : route('login') }}">

                        <div class="action-icon">

                            <i class="bi bi-arrow-left-right"></i>

                        </div>

                        <h3 class="action-title">
                            Sell
                        </h3>

                        <div class="action-subtitle">
                            Sell USDT
                        </div>

                        <i class="bi bi-chevron-right action-arrow"></i>
                    </a>

                </div>


                <!-- WITHDRAW -->

                <div class="col-6 col-md-3">

                    <a class="action-card" href="{{ auth('web')->user()?->role === 'user' ? route('wallet.withdrawals.create') : route('login') }}">

                        <div class="action-icon">

                            <i class="bi bi-wallet2"></i>

                        </div>

                        <h3 class="action-title">
                            Withdraw
                        </h3>

                        <div class="action-subtitle">
                            Get Your Funds
                        </div>

                        <i class="bi bi-chevron-right action-arrow"></i>
                    </a>

                </div>


                <!-- REFERRAL -->

                <div class="col-6 col-md-3">

                    <a class="action-card" href="{{ auth('web')->user()?->role === 'user' ? route('profile.referrals') : route('login') }}" aria-label="Referral earn together">

                        <div class="action-icon">

                            <i class="bi bi-people-fill"></i>

                        </div>

                        <h3 class="action-title">
                            Referral
                        </h3>

                        <div class="action-subtitle">
                            Earn Together
                        </div>

                        <i class="bi bi-chevron-right action-arrow"></i>
                    </a>

                </div>

            </div>

        </section>



            <!-- =========================================
     EMoneyCHANGE RATES
========================================= -->

        <section class="rates-section">

    <!-- HEADER -->
    <div class="rates-header">

        <div class="rates-title">

            <i class="bi bi-bar-chart-fill"></i>

            <span>
                Exchange <b>Rates</b>
            </span>

        </div>

        <div class="live-badge">

            <i class="bi bi-lightning-fill"></i>

            Live Rates

            <span class="live-dot"></span>

        </div>

    </div>


    @forelse ($ratePlans as $plan)
        <article class="rate-card">
            <div class="rate-info">
                <div class="rate-icon {{ $plan->plan_key === 'base' ? 'usdt-icon' : '' }} {{ $plan->plan_key === 'prime' ? 'prime-icon' : '' }} {{ $plan->plan_key === 'vip' ? 'vip-icon' : '' }}">
                    <i class="bi {{ $plan->icon }}" aria-hidden="true"></i>
                </div>
                <div class="rate-details">
                    <div class="rate-name">
                        {{ $plan->name }}
                        @if ($plan->label)
                            <span class="rate-tag">{{ $plan->label }}</span>
                        @endif
                        <span class="rate-tag">
                            {{ $plan->formattedMinimumAmount() }}@if ($plan->formattedMaximumAmount() !== null) – {{ $plan->formattedMaximumAmount() }}@else+@endif USDT
                        </span>
                    </div>
                    <small>1 USDT = {{ $plan->formattedRate() }} INR</small>
                    @if ($plan->description)
                        <small class="rate-description">{{ $plan->description }}</small>
                    @endif
                </div>
            </div>
            <div class="rate-value">
                <div class="rate-number">
                    ₹{{ $plan->formattedRate() }}
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </div>
                <small>1 USDT = {{ $plan->formattedRate() }} INR</small>
            </div>
        </article>
    @empty
        <article class="rate-card"><div class="rate-info"><div class="rate-details"><div class="rate-name">Exchange rates are temporarily unavailable.</div></div></div></article>
    @endforelse

    <section class="withdrawal-calculator" aria-labelledby="withdrawal-calculator-title" data-quote-url="{{ route('exchange.sell.estimate') }}">
        <div class="withdrawal-calculator-heading">
            <span class="withdrawal-calculator-icon"><i class="bi bi-calculator" aria-hidden="true"></i></span>
            <div>
                <h2 id="withdrawal-calculator-title">USDT to INR estimate</h2>
                <p>Choose an amount to see the applicable rate slab.</p>
            </div>
        </div>
        <label for="withdrawal-amount">Enter USDT Amount</label>
        <input class="withdrawal-calculator-input" id="withdrawal-amount" type="number" min="0.00000001" step="0.00000001" inputmode="decimal" autocomplete="off" placeholder="e.g. 5000">
        <p class="withdrawal-calculator-error" id="withdrawal-calculator-error" role="status" hidden></p>
        <dl class="withdrawal-calculator-results" id="withdrawal-calculator-results" aria-live="polite" hidden>
            <div><dt>Applicable Rate Plan</dt><dd id="withdrawal-plan-name">—</dd></div>
            <div><dt>INR per USDT</dt><dd id="withdrawal-rate">—</dd></div>
            <div><dt>USDT Amount</dt><dd id="withdrawal-amount-value">—</dd></div>
            <div class="withdrawal-estimate"><dt>Estimated INR Amount</dt><dd id="withdrawal-estimated-inr">—</dd></div>
        </dl>
        <p class="withdrawal-calculator-disclaimer">Informational estimate only, subject to verification and applicable fees. Confirm the active rate before submitting your sell request.</p>
    </section>

    <section class="trade-usdt-banner">
                  <div class="trade-content">

            <h2>
                Trade <span>USDT</span>
            </h2>

            <div class="trade-points">
                <span>Secure</span>
                <b>•</b>
                <span>Fast</span>
                <b>•</b>
                <span>Reliable</span>
            </div>

        </div>

        <div class="trade-right">
            <h3>Global Access</h3>
            <span>Anytime, Anywhere</span>
        </div>
    </div>

</section>
    </main>



        <!-- =====================================
         WHATSAPP
    ====================================== -->

        <a href="{{ route('contact') }}" aria-label="Contact support"
            class="whatsapp">

            <i class="bi bi-headset"></i>

        </a>



        <!-- =====================================
         BOTTOM NAV
    ====================================== -->

        @include('partials.bottom-nav', ['active' => 'exchange', 'variant' => 'exchange'])

    </div>
@endsection

@section('scripts')
    <script>
        (() => {
            const calculator = document.querySelector('.withdrawal-calculator');
            const amountInput = document.getElementById('withdrawal-amount');
            const results = document.getElementById('withdrawal-calculator-results');
            const error = document.getElementById('withdrawal-calculator-error');
            if (!calculator || !amountInput || !results || !error) return;

            const output = {
                plan: document.getElementById('withdrawal-plan-name'),
                rate: document.getElementById('withdrawal-rate'),
                amount: document.getElementById('withdrawal-amount-value'),
                inr: document.getElementById('withdrawal-estimated-inr'),
            };
            let pending;
            let controller;
            let sequence = 0;

            const reset = () => {
                results.hidden = true;
                error.hidden = true;
                error.textContent = '';
            };
            const formatInr = (value) => {
                const [whole, fraction] = value.split('.');
                return `₹${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction ?? '00'}`;
            };

            amountInput.addEventListener('input', () => {
                clearTimeout(pending);
                controller?.abort();
                reset();

                if (!amountInput.value) return;

                pending = setTimeout(async () => {
                    const requestSequence = ++sequence;
                    controller = new AbortController();
                    const url = new URL(calculator.dataset.quoteUrl, window.location.href);
                    url.searchParams.set('amount', amountInput.value);

                    try {
                        const response = await fetch(url, {
                            headers: { Accept: 'application/json' },
                            signal: controller.signal,
                        });
                        const data = await response.json();
                        if (requestSequence !== sequence) return;

                        if (!response.ok) {
                            throw new Error(data.errors?.amount?.[0] ?? 'Unable to calculate this estimate.');
                        }

                        output.plan.textContent = data.plan;
                        output.rate.textContent = `₹${data.rate}`;
                        output.amount.textContent = `${data.amount} USDT`;
                        output.inr.textContent = formatInr(data.estimated_inr);
                        results.hidden = false;
                    } catch (exception) {
                        if (exception.name === 'AbortError' || requestSequence !== sequence) return;
                        error.textContent = exception.message;
                        error.hidden = false;
                    }
                }, 180);
            });
        })();
    </script>
@endsection
