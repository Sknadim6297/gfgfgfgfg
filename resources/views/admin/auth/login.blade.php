@extends('layouts.admin-auth')
@section('title', 'Quick PayMoney | Admin Login')
@section('auth-heading')
    <h1>Welcome back</h1>
    <p>Sign in securely to manage your platform.</p>
@endsection
@section('auth-content')
    <form class="admin-auth-form" method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="admin-email">Admin email</label>
        <input class="admin-auth-input" id="admin-email" type="email" name="email" value="{{ old('email') }}" placeholder="name@company.com" autocomplete="username" required autofocus>
        @error('email')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <label for="admin-password">Password</label>
        <input class="admin-auth-input" id="admin-password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        @error('password')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <div class="admin-auth-form-row"><a href="{{ route('admin.password.request') }}">Forgot password?</a></div>
        <button class="admin-auth-submit" type="submit">Continue securely <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
    <p class="admin-auth-note"><i class="bi bi-shield-check" aria-hidden="true"></i> Two-factor verification is required after sign-in.</p>
@endsection
