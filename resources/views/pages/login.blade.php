@extends('layouts.app')

@section('title', 'Quick PayMoney | Login')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap"
          rel="stylesheet">
@endsection

@section('styles')
<link href="{{ asset('assets/css/login.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="main-wrapper">


    <!-- =====================================
         HEADER
    ====================================== -->

    @include('partials.account-header', ['actionRoute' => 'register', 'actionLabel' => 'Register', 'actionClass' => 'header-btn text-decoration-none', 'showContact' => true])



    <!-- =====================================
         LOGIN
    ====================================== -->

    <main class="login-area">

        <div class="login-card">


            <!-- HEADING -->

            <div class="login-heading">

                <h1>
                    Welcome Back
                </h1>

                <p>
                    Login with your Email and Password
                </p>

            </div>



            <!-- FORM -->

            <form action="{{ route('login.store') }}"
                  method="post"
                  autocomplete="on">
                @csrf


                <!-- EMAIL -->

                <div class="input-group-custom">

                    <input type="email"
                           name="email"
                           class="form-control-custom"
                           placeholder="Email Address"
                           value="{{ old('email') }}"
                           autocomplete="username"
                           required>

                </div>



                <!-- PASSWORD -->

                <div class="input-group-custom">

                    <input type="password"
                           name="password"
                           id="password"
                           class="form-control-custom password-input"
                           placeholder="Password"
                           autocomplete="current-password"
                           required>

                    <button type="button"
                            class="password-toggle"
                            onclick="togglePassword()">

                        <i class="bi bi-eye-fill"
                           id="passwordIcon"></i>

                    </button>

                </div>



                <!-- FORGOT -->

                <div class="forgot-row">

                    <a href="{{ route('password.request') }}"
                       class="forgot-link">

                        Forgot Password?

                    </a>

                </div>



                <!-- LOGIN -->

                <button type="submit"
                        class="login-btn">

                    <i class="bi bi-box-arrow-in-right"></i>

                    Login Now

                </button>


            </form>



            <!-- REGISTER -->

            <div class="register-text">

                Don't have an account?

                <a href="{{ route('register') }}">
                    Register
                </a>

            </div>


        </div>

    </main>

      <a href="{{ route('contact') }}" aria-label="Contact support"
            class="whatsapp">

            <i class="bi bi-headset"></i>

        </a>

    <!-- =====================================
         BOTTOM NAV
    ====================================== -->

    @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])

</div>
@endsection

@section('scripts')
    <script src="{{ asset('assets/js/auth.js') }}" defer></script>
@endsection
