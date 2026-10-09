@extends('layouts.app')
@section('title', 'Quick PayMoney | Reset Password')
@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@endsection
@section('styles')
    <link href="{{ asset('assets/css/login.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection
@section('content')
    <div class="main-wrapper">
        @include('partials.account-header', ['actionRoute' => 'login', 'actionLabel' => 'Login', 'actionClass' => 'header-btn text-decoration-none', 'showContact' => true])
        <main class="login-area"><section class="login-card">
            <div class="login-heading"><h1>Forgot password?</h1><p>We'll email a secure reset link if the account exists.</p></div>
            <form method="POST" action="{{ route('password.email') }}">@csrf
                <div class="input-group-custom"><input class="form-control-custom" type="email" name="email" value="{{ old('email') }}" placeholder="Email Address" autocomplete="email" required></div>
                <button class="login-btn" type="submit">Send reset link</button>
            </form>
        </section></main>
    </div>
@endsection
