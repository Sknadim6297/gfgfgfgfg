@extends('layouts.app')
@section('title', 'Quick PayMoney | Set New Password')
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
            <div class="login-heading"><h1>Set a new password</h1><p>Use at least 12 characters with upper/lowercase, a number and a symbol.</p></div>
            <form method="POST" action="{{ route('password.update') }}">@csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="input-group-custom"><input class="form-control-custom" type="email" name="email" value="{{ old('email', $email) }}" placeholder="Email Address" autocomplete="email" required></div>
                <div class="input-group-custom"><input class="form-control-custom" type="password" name="password" placeholder="New Password" minlength="12" autocomplete="new-password" required></div>
                <div class="input-group-custom"><input class="form-control-custom" type="password" name="password_confirmation" placeholder="Confirm Password" minlength="12" autocomplete="new-password" required></div>
                <button class="login-btn" type="submit">Reset password</button>
            </form>
        </section></main>
    </div>
@endsection
