@extends('layouts.admin-auth')
@section('title', 'Quick PayMoney | Reset Admin Password')
@section('auth-heading')
    <h1>Set a new password</h1>
    <p>Use at least 12 characters with mixed case, a number and a symbol.</p>
@endsection
@section('auth-content')
    <form class="admin-auth-form" method="POST" action="{{ route('admin.password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="admin-email">Admin email</label>
        <input class="admin-auth-input" id="admin-email" type="email" name="email" value="{{ old('email', $email) }}" placeholder="name@company.com" autocomplete="email" required>
        @error('email')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <label for="admin-password">New password</label>
        <input class="admin-auth-input" id="admin-password" type="password" name="password" placeholder="At least 12 characters" minlength="12" autocomplete="new-password" required>
        @error('password')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <label for="admin-password-confirmation">Confirm new password</label>
        <input class="admin-auth-input" id="admin-password-confirmation" type="password" name="password_confirmation" placeholder="Re-enter your new password" minlength="12" autocomplete="new-password" required>
        <button class="admin-auth-submit" type="submit">Reset password <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
@endsection
