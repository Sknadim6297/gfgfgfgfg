@extends('layouts.admin-auth')
@section('title', 'Quick PayMoney | Admin Password Recovery')
@section('auth-heading')
    <h1>Reset your password</h1>
    <p>If the admin account exists, a secure reset link will be sent.</p>
@endsection
@section('auth-content')
    <form class="admin-auth-form" method="POST" action="{{ route('admin.password.email') }}">
        @csrf
        <label for="admin-email">Admin email</label>
        <input class="admin-auth-input" id="admin-email" type="email" name="email" value="{{ old('email') }}" placeholder="name@company.com" autocomplete="email" required autofocus>
        @error('email')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <button class="admin-auth-submit" type="submit">Send reset link <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
    <a class="admin-auth-back" href="{{ route('admin.login') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to admin sign in</a>
@endsection
