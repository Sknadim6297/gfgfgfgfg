<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Quick PayMoney | USDT to INR Exchange</title>

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link href="{{ asset('assets/landing/css/landing.css') }}" rel="stylesheet">
</head>

<body data-login-url="{{ route('login') }}">

<!-- =========================
     NAVBAR
========================= -->

<nav class="navbar navbar-expand-lg main-navbar sticky-top">

    <div class="container">

        <a class="navbar-brand" href="#home">
            Quick <span>PayMoney</span>
        </a>

        <button
            class="navbar-toggler border-0"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNav"
        >
            <i class="bi bi-list text-warning fs-2"></i>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">

            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link" href="#home">Home</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#about">About</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#how-it-works">
                        How It Works
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="#support">
                        Support
                    </a>
                </li>

            </ul>

            <div class="d-flex gap-2">

                <a href="{{ route('register') }}" class="register-btn">
                    Register
                </a>

                <a href="{{ route('login') }}" class="login-btn">
                    Login
                </a>

            </div>

        </div>

    </div>

</nav>

<!-- =========================
     HERO
========================= -->

<section class="hero" id="home">

    <div class="container">

        <div class="row align-items-center g-5">

            <div class="col-lg-6">

                <div class="hero-badge">
                    <span class="live-dot"></span>
                    LIVE EXCHANGE PLATFORM
                </div>

                <h1>
                    Convert Your
                    <span>USDT</span>
                    Into INR
                </h1>

                <p class="hero-text">
                    Fast, simple and secure digital currency conversion.
                    Check the live rate and calculate your USDT or USD
                    value in Indian Rupees instantly.
                </p>

                <div class="hero-buttons">

                    <a href="#exchange"
                       class="btn-yellow">
                        Exchange Now
                        <i class="bi bi-arrow-right ms-2"></i>
                    </a>

                    <a href="#how-it-works"
                       class="btn-outline-yellow">
                        How It Works
                    </a>

                </div>

            </div>


            <!-- RATE CARD -->

            <div class="col-lg-6" id="exchange">

                <div class="rate-card">

                    <div class="rate-header">

                        <div class="rate-live">
                            <i class="bi bi-circle-fill"></i>
                            LIVE RATE
                        </div>

                        <div class="rate-time">
                            Updated just now
                        </div>

                    </div>


                    <div class="coin-box">

                        <div class="coin coin-usdt">
                            ₮
                        </div>

                        <div class="exchange-icon">
                            ⇄
                        </div>

                        <div class="coin coin-inr">
                            ₹
                        </div>

                    </div>


                    <div class="text-center">

                        <div class="rate-value">
                            1 USDT =
                            <span id="usdtRateDisplay">
                                ₹110
                            </span>
                        </div>

                        <div class="rate-subtitle">
                            USDT to Indian Rupee conversion
                        </div>

                    </div>


                    <!-- CALCULATOR -->

                    <div class="calculator-card">

                        <div class="calc-title">
                            <i class="bi bi-calculator me-2"></i>
                            Quick Converter
                        </div>


                        <div class="mb-3">

                            <label class="form-label">
                                Enter USDT
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                id="usdtInput"
                                placeholder="Enter amount"
                                value="1"
                                min="0"
                                oninput="convertUSDT()"
                            >

                        </div>


                        <div class="result-box">

                            <div class="result-label">
                                You Receive
                            </div>

                            <div class="result-value">
                                ₹ <span id="usdtResult">
                                    110.00
                                </span>
                            </div>

                        </div>

                    </div>


                    <button
                        class="btn btn-yellow w-100 mt-3"
                        onclick="startExchange()"
                    >
                        Exchange Now
                        <i class="bi bi-arrow-down-circle ms-2"></i>
                    </button>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     STATS
========================= -->

<section class="stats-section">

    <div class="container">

        <div class="row">

            <div class="col-6 col-lg-3">
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">
                        Platform Availability
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-item">
                    <div class="stat-number">Fast</div>
                    <div class="stat-label">
                        Processing
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-item">
                    <div class="stat-number">Secure</div>
                    <div class="stat-label">
                        Transactions
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-item">
                    <div class="stat-number">INR</div>
                    <div class="stat-label">
                        Local Currency
                    </div>
                </div>
            </div>

        </div>

    </div>

</section>


<!-- =========================
     ABOUT
========================= -->

<section class="section" id="about">

    <div class="container">

        <div class="about-box">

            <div class="row align-items-center g-5">

                <div class="col-lg-6">

                    <div class="text-warning fw-bold mb-3">
                        ABOUT QUICK PAYMONEY
                    </div>

                    <h2>
                        Simple
                        <span>Digital</span>
                        Currency Conversion
                    </h2>

                </div>

                <div class="col-lg-6">

                    <p>
                        Quick PayMoney is designed to provide a simple
                        interface for checking and calculating digital
                        currency values against Indian Rupees.
                    </p>

                    <p>
                        Users can check the current platform rate,
                        enter an amount and instantly view the
                        corresponding INR value.
                    </p>

                    <a href="{{ route('register') }}"
                       class="btn-yellow">
                        Create Account
                    </a>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     FEATURES
========================= -->

<section class="section pt-0">

    <div class="container">

        <h2 class="section-title">
            Why <span>Quick PayMoney?</span>
        </h2>

        <p class="section-subtitle mb-5">
            Built with a clean interface focused on speed,
            simplicity and easy currency conversion.
        </p>


        <div class="row g-4">

            <div class="col-md-6 col-lg-3">

                <div class="feature-card">

                    <div class="feature-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </div>

                    <h4>Fast</h4>

                    <p>
                        Quickly calculate your digital currency
                        value without complicated steps.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-3">

                <div class="feature-card">

                    <div class="feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <h4>Secure</h4>

                    <p>
                        Designed with security-focused user
                        experience and protected account access.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-3">

                <div class="feature-card">

                    <div class="feature-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <h4>Live Rate</h4>

                    <p>
                        Display the current platform conversion
                        rate clearly before starting an exchange.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-lg-3">

                <div class="feature-card">

                    <div class="feature-icon">
                        <i class="bi bi-phone"></i>
                    </div>

                    <h4>Mobile Ready</h4>

                    <p>
                        Fully responsive experience across
                        desktop, tablet and mobile devices.
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     HOW IT WORKS
========================= -->

<section class="section bg-black" id="how-it-works">

    <div class="container">

        <h2 class="section-title">
            How It <span>Works</span>
        </h2>

        <p class="section-subtitle mb-5">
            Converting your USDT value into INR can be done
            in just a few simple steps.
        </p>


        <div class="row g-4">

            <div class="col-md-4">

                <div class="step">

                    <div class="step-number">
                        1
                    </div>

                    <h5>
                        Enter Amount
                    </h5>

                    <p>
                        Enter the amount of USDT you want
                        to calculate or exchange.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="step">

                    <div class="step-number">
                        2
                    </div>

                    <h5>
                        Check Rate
                    </h5>

                    <p>
                        Review the displayed USDT to INR
                        conversion rate.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="step">

                    <div class="step-number">
                        3
                    </div>

                    <h5>
                        Start Exchange
                    </h5>

                    <p>
                        Continue to the exchange process
                        after reviewing your conversion.
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     USD / USDT CALCULATOR
========================= -->

<section class="section" id="calculator">

    <div class="container">

        <h2 class="section-title">
            Currency <span>Calculator</span>
        </h2>

        <p class="section-subtitle mb-5">
            Calculate USDT or USD values in Indian Rupees.
        </p>


        <div class="row justify-content-center">

            <div class="col-lg-7">

                <div class="rate-card">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label class="form-label">
                                Currency
                            </label>

                            <select
                                class="form-select"
                                id="currencyType"
                                onchange="calculateCurrency()"
                            >
                                <option value="USDT">
                                    USDT
                                </option>

                                <option value="USD">
                                    USD
                                </option>
                            </select>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Amount
                            </label>

                            <input
                                type="number"
                                class="form-control"
                                id="currencyAmount"
                                value="1"
                                min="0"
                                oninput="calculateCurrency()"
                            >

                        </div>

                    </div>


                    <div class="result-box mt-4 text-center">

                        <div class="result-label">
                            Estimated INR Value
                        </div>

                        <div class="result-value"
                             style="font-size:42px;">

                            ₹ <span id="currencyResult">
                                110.00
                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     SUPPORT
========================= -->

<section class="section pt-0" id="support">

    <div class="container">

        <h2 class="section-title">
            Need <span>Support?</span>
        </h2>

        <p class="section-subtitle mb-5">
            Our support team is available to help you
            understand the platform and exchange process.
        </p>

        <div class="row justify-content-center">

            <div class="col-md-6 col-lg-5">

                <div class="support-card text-center">

                    <i class="bi bi-envelope"></i>

                    <h5>
                        Email Support
                    </h5>

                    <p>
                        Send us your questions and our team
                        will assist you.
                    </p>

                    <a href="mailto:support@quickpaymoney.in"
                       class="btn-outline-yellow">
                        Email Us
                    </a>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     REGISTER CTA
========================= -->

<section class="cta-section" id="register">

    <div class="container">

        <div class="cta-box">

            <h2>
                Ready to Start
                <span>Converting?</span>
            </h2>

            <p>
                Create your account and access the Quick
                PayMoney platform.
            </p>

            <div class="d-flex justify-content-center gap-3 flex-wrap">

                <a href="{{ route('register') }}" class="btn-yellow">
                    Register Now
                </a>

                <a href="{{ route('login') }}" class="btn-outline-yellow">
                    Login
                </a>

            </div>

        </div>

    </div>

</section>


<!-- =========================
     FOOTER
========================= -->

<footer>

    <div class="container">

        <div class="row g-5">

            <div class="col-lg-5">

                <div class="footer-brand">
                    Quick <span>PayMoney</span>
                </div>

                <p class="mt-3">
                    A simple digital currency conversion
                    platform for checking USDT / USD values
                    against INR.
                </p>

            </div>


            <div class="col-6 col-lg-2">

                <h6>
                    Platform
                </h6>

                <ul>

                    <li>
                        <a href="#home">Home</a>
                    </li>

                    <li>
                        <a href="#about">About</a>
                    </li>

                    <li>
                        <a href="#calculator">
                            Calculator
                        </a>
                    </li>

                    <li>
                        <a href="#support">
                            Support
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-6 col-lg-2">

                <h6>
                    Account
                </h6>

                <ul>

                    <li>
                        <a href="{{ route('register') }}">
                            Register
                        </a>

                    </li>

                    <li>
                        <a href="{{ route('login') }}">
                            Login
                        </a>
                    </li>

                    <li>
                        <a href="#">
                            Terms
                        </a>
                    </li>

                    <li>
                        <a href="#">
                            Privacy
                        </a>
                    </li>

                </ul>

            </div>


            <div class="col-lg-3">

                <h6>
                    Contact
                </h6>

                <ul>

                    <li>
                        <a href="mailto:support@quickpaymoney.in">
                            support@quickpaymoney.in
                        </a>
                    </li>

                </ul>

            </div>

        </div>


        <div class="copyright text-center">

            © 2026 Quick PayMoney.
            All Rights Reserved.

        </div>

    </div>

</footer>




<!-- =========================
     MOBILE BOTTOM NAV
========================= -->

<div class="mobile-bottom-nav">



    <a href="#exchange">
        <i class="bi bi-arrow-left-right"></i>
        Exchange
    </a>

    <a href="#calculator">
        <i class="bi bi-calculator"></i>
        Calculator
    </a>

    <a href="#support">
        <i class="bi bi-headset"></i>
        Support
    </a>

    <a href="{{ route('register') }}">
        <i class="bi bi-person"></i>
        Account
    </a>

</div>


<!-- =========================
     BOOTSTRAP
========================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

<script src="{{ asset('assets/landing/js/landing.js') }}"></script>
@include('partials.notifications')
@vite('resources/js/session-notifications.js')

</body>
</html>