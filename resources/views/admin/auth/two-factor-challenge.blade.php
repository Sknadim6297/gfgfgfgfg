@extends('layouts.admin-auth')
@section('title', 'Quick PayMoney | Admin Verification')
@section('auth-heading')
    <h1>Two-factor verification</h1>
    <p>Enter the current code from your authenticator app.</p>
@endsection
@section('auth-content')
    @if ($demoCode)
        <p class="admin-auth-demo"><strong>Local development demo code (valid for 5 minutes):</strong><code>{{ $demoCode }}</code></p>
    @endif
    <form class="admin-auth-form" method="POST" action="{{ route('admin.2fa.verify') }}">
        @csrf
        <label for="authenticator-code">6-digit verification code</label>
        <input class="admin-auth-input admin-auth-code-input" id="authenticator-code" type="text" inputmode="numeric" name="code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
        @error('code')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <button class="admin-auth-submit" type="submit">Verify and continue <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
    <p class="admin-auth-note"><i class="bi bi-lock-fill" aria-hidden="true"></i> Verification codes are one-time use.</p>
@endsection
