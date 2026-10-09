@extends('layouts.app')

@section('title', 'Quick Pay Money | Register')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
@endsection

@section('styles')
<link href="{{ asset('assets/css/register.css') }}" rel="stylesheet">
<link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">


        <!-- HEADER -->

        @include('partials.account-header', ['actionRoute' => 'login', 'actionLabel' => 'Login', 'actionClass' => 'header-btn', 'showContact' => true])



        <!-- REGISTER -->

        <main class="register-area">

        <div class="register-card">


            <!-- HEADING -->

            <div class="register-heading">

                <h1>
                    Let's Get Started
                </h1>

                <p>
                    Create your Quick  Pay account.
                </p>

            </div>



            <form action="{{ route('register.store') }}"
                  method="post"
                  autocomplete="on">
                @csrf


                <!-- NAME -->

                <div class="input-group-custom">

                    <input type="text"
                           name="name"
                           class="form-control-custom"
                           placeholder="Full Name"
                           value="{{ old('name') }}"
                           autocomplete="name"
                           required>

                </div>


                <!-- MOBILE -->

                <div class="input-group-custom">

                    <input type="tel"
                           name="mobile"
                           class="form-control-custom"
                           placeholder="Mobile Number"
                           maxlength="10"
                           inputmode="numeric"
                           value="{{ old('mobile') }}"
                           autocomplete="tel">

                </div>


                <!-- EMAIL -->

                <div class="input-group-custom">

                    <input type="email"
                           name="email"
                           class="form-control-custom"
                           placeholder="Email Address"
                           value="{{ old('email') }}"
                           autocomplete="email"
                           required>

                </div>

                <div class="input-group-custom">
                    <input type="text"
                           name="referral_code"
                           class="form-control-custom"
                           placeholder="Referral Code (optional)"
                           value="{{ old('referral_code', request('ref')) }}"
                           maxlength="12"
                           autocapitalize="characters"
                           autocomplete="off">
                    @error('referral_code')
                        <small class="register-field-error">{{ $message }}</small>
                    @enderror
                </div>


                <!-- PASSWORD -->

                <div class="input-group-custom password-wrapper">

                    <input type="password"
                           name="password"
                           id="password"
                           class="form-control-custom"
                           placeholder="Password"
                           minlength="12"
                           autocomplete="new-password"
                           required>

                    <button type="button"
                            class="password-toggle"
                            onclick="togglePassword('password','passwordIcon')">

                        <i class="bi bi-eye-fill"
                           id="passwordIcon"></i>

                    </button>

                </div>


                <!-- CONFIRM PASSWORD -->

                <div class="input-group-custom password-wrapper">

                    <input type="password"
                           name="password_confirmation"
                           id="confirmPassword"
                           class="form-control-custom"
                           placeholder="Confirm Password"
                           minlength="12"
                           autocomplete="new-password"
                           required>

                    <button type="button"
                            class="password-toggle"
                            onclick="togglePassword('confirmPassword','confirmIcon')">

                        <i class="bi bi-eye-fill"
                           id="confirmIcon"></i>

                    </button>

                </div>



                <!-- GENDER -->

                <div class="gender-label">
                    Select Gender
                </div>


                <div class="gender-options">

                    <div class="gender-option">

                        <input type="radio"
                               name="gender"
                               id="male"
                               value="Male"
                               required>

                        <label for="male">
                            Male
                        </label>

                    </div>


                    <div class="gender-option">

                        <input type="radio"
                               name="gender"
                               id="female"
                               value="Female">

                        <label for="female">
                            Female
                        </label>

                    </div>


                    <div class="gender-option">

                        <input type="radio"
                               name="gender"
                               id="other"
                               value="Other">

                        <label for="other">
                            Other
                        </label>

                    </div>

                </div>



                <!-- TERMS -->

                <div class="terms-row">

                    <input type="checkbox"
                           id="terms"
                           name="terms"
                           required>

                    <label for="terms">

                                I understand that this portal does not hold funds or perform exchange settlements.

                    </label>

                </div>



                <!-- REGISTER -->

                <button type="submit"
                        class="register-btn">

                    <i class="bi bi-person-plus-fill"></i>

                    Register

                </button>


            </form>



            <!-- LOGIN -->

            <div class="login-text">

                Already have an account?

                <a href="{{ route('login') }}">
                    Login
                </a>

            </div>


        </div>

        </main>

          <a href="{{ route('contact') }}" aria-label="Contact support"
            class="whatsapp">

            <i class="bi bi-headset"></i>

        </a>

        <!-- BOTTOM NAV -->

        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])

    </div>


@endsection

@section('scripts')
    <script src="{{ asset('assets/js/auth.js') }}" defer></script>
@endsection
