@extends('layouts.app')

@section('title', 'Quick PayMoney | Home')

@section('font')
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
@endsection

@section('styles')
<link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">

        @include('partials.home-header')


        <!-- CONTENT -->
        <main class="content-area">

        <!-- LIVE RATE -->
        <section class="rate-card">
            <div class="live-title">
                <span class="live-dot"></span>
                {{ $baseRate?->label ?: $baseRate?->name ?: 'LIVE RATE' }}
            </div>

            <div class="rate-title">
                @if ($baseRate)
                    1 USDT = <span><i class="bi bi-currency-rupee"></i>{{ $baseRate->formattedRate() }}</span>
                @else
                    <span>Rate temporarily unavailable</span>
                @endif
            </div>

            <div class="rate-subtitle">
                Fast &amp; secure USDT
                <br>
                to INR exchange
            </div>


            <!-- COINS -->

            <div class="coin-area">

                <div class="coin usdt-coin">
                    ₮
                </div>

                <div class="exchange-arrow">
                    ⇄
                </div>

                <div class="coin inr-coin">
                    <i class="bi bi-currency-rupee"></i>
                </div>

            </div>


            <!-- BUTTON -->

            <a class="exchange-btn text-decoration-none" href="{{ route('exchange') }}">
                Exchange Now
                <i class="bi bi-arrow-down"></i>
            </a>

        </section>


        <!-- PLATFORM STATS -->

        <section class="stats-card">

            <div class="section-title">

                <span class="title-line"></span>

                <span class="green-dot"></span>

                LIVE PLATFORM STATS

                <span class="title-line"></span>

            </div>


            <div class="stats-inner">

                <div class="row g-0">

                    <!-- CLIENTS -->

                    <div class="col-4 stat-item">

                        <div class="stat-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>

                        <div class="stat-label">
                            OVERALL<br>
                            ACTIVE CLIENTS
                        </div>

                        <div class="stat-value">
                            500
                        </div>

                    </div>


                    <!-- DEPOSIT -->

                    <div class="col-4 stat-item">

                        <div class="stat-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </div>

                        <div class="stat-label">
                            OVERALL<br>
                            DEPOSIT
                        </div>

                        <div class="stat-value">
                            $2.5M
                        </div>

                    </div>


                    <!-- INR -->

                    <div class="col-4 stat-item inr">

                        <div class="stat-icon">
                            <i class="bi bi-currency-rupee"></i>
                        </div>

                        <div class="stat-label">
                            OVERALL<br>
                            INR
                        </div>

                        <div class="stat-value green">
                            <i class="bi bi-currency-rupee"></i>27.5Cr
                        </div>

                    </div>

                </div>

     <div class="conversion-card">
         <div class="table-responsive">

        <table class="conversion-table">

            <thead>
                <tr>
                    <th>CLIENT</th>
                    <th>DEPOSIT</th>
                    <th>CONVERTED</th>
                </tr>
            </thead>

            <tbody>
                <tr><td>+91 96****3461</td><td class="usd">↓ $4,187</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>4,60,570</td></tr>
                <tr><td>+91 91****8754</td><td class="usd">↓ $3,814</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>4,19,540</td></tr>
                <tr><td>+91 88****2319</td><td class="usd">↓ $8,062</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>8,86,820</td></tr>
                <tr><td>+91 90****5467</td><td class="usd">↓ $14,633</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>16,82,795</td></tr>
                <tr><td>+91 93****7925</td><td class="usd">↓ $7,425</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>8,16,750</td></tr>
                <tr><td>+91 95****3186</td><td class="usd">↓ $3,118</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>3,42,980</td></tr>
                <tr><td>+91 87****6542</td><td class="usd">↓ $603</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>66,330</td></tr>
                <tr><td>+91 98****9317</td><td class="usd">↓ $12,687</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>14,59,005</td></tr>
                <tr><td>+91 97****2458</td><td class="usd">↓ $7,481</td><td class="inr">↑ <i class="bi bi-currency-rupee"></i>8,22,910</td></tr>
            </tbody>

        </table>

    </div>

</div>

            </div>

        </section>

            <!-- WHY CHOOSE US -->
        <section class="why-section">

            <div class="section-heading">

                <span class="heading-line"></span>

                <span class="green-dot"></span>

                WHY CHOOSE US

                <span class="heading-line"></span>

            </div>


            <div class="why-grid">

                <!-- Best Rate -->
                <div class="why-card">

                    <div class="why-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <div class="why-content">
                        <h4>Best Rate</h4>
                        <p>Quick imum value</p>
                    </div>

                </div>


                <!-- Fast Transaction -->
                <div class="why-card">

                    <div class="why-icon">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </div>

                    <div class="why-content">
                        <h4>Fast Transaction</h4>
                        <p>Quick processing</p>
                    </div>

                </div>


                <!-- Secure -->
                <div class="why-card">

                    <div class="why-icon secure-icon">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>

                    <div class="why-content">
                        <h4>Secure</h4>
                        <p>Protected transfer</p>
                    </div>

                </div>


                <!-- No Lien Freeze -->
                <div class="why-card">

                    <div class="why-icon green-icon">
                        <i class="bi bi-unlock-fill"></i>
                    </div>

                    <div class="why-content">
                        <h4>No Lien Freeze</h4>
                        <p>Smooth withdrawals</p>
                    </div>

                </div>

            </div>

        </section>


            <!-- TRUST & SUPPORT -->
        <section class="trust-section">

    <!-- Freeze-Free Transactions -->
    <div class="trust-card">

        <div class="trust-icon">
            <i class="bi bi-unlock-fill"></i>
        </div>

        <div class="trust-content">
            <h4>Freeze-Free Transactions</h4>
            <p>
                Fast, secure &amp; uninterrupted payments every time.
            </p>
        </div>

    </div>


    <!-- Trusted Banking Network -->
    <div class="trust-card">

        <div class="trust-icon">
            <i class="bi bi-bank2"></i>
        </div>

        <div class="trust-content">
            <h4>Trusted Banking Network</h4>
            <p>
                Partnered with multiple banks for secure transfers.
            </p>
        </div>

    </div>


    <!-- 24/7 Customer Support -->
    <div class="trust-card">

        <div class="trust-icon support-icon">
            <i class="bi bi-whatsapp"></i>
        </div>

        <div class="trust-content">
            <h4>24/7 Customer Support</h4>
            <p>
                Professional assistance whenever you need help.
            </p>
        </div>

    </div>

</section>

    </main>


        <!-- WHATSAPP -->

        <a href="{{ route('contact') }}" aria-label="Contact support"
            class="whatsapp">

            <i class="bi bi-headset"></i>

        </a>


        <!-- BOTTOM NAV -->

        @include('partials.bottom-nav', ['active' => 'home', 'variant' => 'standard'])

    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>
@endsection
