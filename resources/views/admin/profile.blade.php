@extends('layouts.admin')
@section('title', 'Quick PayMoney | Admin Profile')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">ACCOUNT SETTINGS</span><h1>Admin profile</h1><p>Manage your administrator identity and account security.</p></div>
        <span class="admin-record-count"><i class="bi bi-shield-check" aria-hidden="true"></i> Protected account</span>
    </div>
    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">PERSONAL DETAILS</span><h2>Profile information</h2></div></div>
            <div class="admin-profile-identity"><span class="admin-avatar admin-avatar-large" aria-hidden="true">{{ mb_substr($admin->name, 0, 1) }}</span><span><strong>{{ $admin->name }}</strong><small>{{ $admin->email }}</small></span></div>
            <form class="admin-form" method="POST" action="{{ route('admin.profile.update') }}">
                @csrf @method('PUT')
                <label for="name">Display name</label><input class="portal-input" id="name" name="name" value="{{ old('name', $admin->name) }}" maxlength="120" required>
                @error('name')<span class="admin-field-error">{{ $message }}</span>@enderror
                <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save profile</button>
            </form>
        </section>
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">SIGN-IN PROTECTION</span><h2>Two-factor authentication</h2></div></div>
            <div class="admin-security-status"><span class="admin-security-check"><i class="bi bi-check2" aria-hidden="true"></i></span><div><strong>Enabled and required</strong><p>Admin routes require an authenticator code for each new sign-in.</p></div></div>
            <div class="admin-security-detail"><i class="bi bi-fingerprint" aria-hidden="true"></i><span>One-time codes are replay-protected.</span></div>
        </section>
    </div>
    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">CREDENTIALS</span><h2>Change password</h2><p>Choose a unique password you do not use elsewhere.</p></div><i class="bi bi-key admin-panel-heading-icon" aria-hidden="true"></i></div>
        <form class="admin-form admin-password-form" method="POST" action="{{ route('admin.password.change') }}">
            @csrf @method('PUT')
            <div class="admin-form-columns">
                <div><label for="current_password">Current password</label><input class="portal-input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>@error('current_password')<span class="admin-field-error">{{ $message }}</span>@enderror</div>
                <div><label for="password">New password</label><input class="portal-input" type="password" id="password" name="password" minlength="12" autocomplete="new-password" required>@error('password')<span class="admin-field-error">{{ $message }}</span>@enderror</div>
                <div><label for="password_confirmation">Confirm new password</label><input class="portal-input" type="password" id="password_confirmation" name="password_confirmation" minlength="12" autocomplete="new-password" required></div>
            </div>
            <p class="admin-form-help">Use at least 12 characters with mixed case, a number and a symbol.</p>
            <button class="admin-button" type="submit"><i class="bi bi-lock" aria-hidden="true"></i> Change password</button>
        </form>
    </section>
@endsection
