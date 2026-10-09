@extends('layouts.admin-auth')
@section('title', 'Quick PayMoney | Set Up Admin 2FA')
@section('auth-heading')
    <h1>Secure your account</h1>
    <p>Set up an authenticator app to protect administrator access.</p>
@endsection
@section('auth-content')
    <div class="admin-auth-security-callout"><i class="bi bi-phone" aria-hidden="true"></i><p>Scan the provisioning URI or enter the secret in your authenticator app, then verify a current code.</p></div>
    <div class="admin-auth-secret"><span>Authenticator secret</span><code>{{ $secret }}</code></div>
    <div class="admin-auth-secret"><span>Provisioning URI</span><code>{{ $provisioningUri }}</code></div>
    @if ($demoCode)
        <p class="admin-auth-demo"><strong>Local development demo code (valid for 5 minutes):</strong><code>{{ $demoCode }}</code></p>
    @endif
    <form class="admin-auth-form" method="POST" action="{{ route('admin.2fa.enable') }}">
        @csrf
        <label for="authenticator-code">6-digit authenticator code</label>
        <input class="admin-auth-input admin-auth-code-input" id="authenticator-code" type="text" inputmode="numeric" name="code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
        @error('code')<span class="admin-auth-field-error">{{ $message }}</span>@enderror
        <button class="admin-auth-submit" type="submit">Enable two-factor authentication <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    </form>
@endsection
