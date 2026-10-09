@extends('layouts.app')

@section('title', 'Quick PayMoney | Reset Password')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area profile-dashboard profile-subpage">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('profile') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Profile</a>
            <section class="profile-content-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></span><div><h2>Reset Password</h2><p>Verify your current password and choose a strong new password.</p></div></div>
                <form class="profile-form" method="POST" action="{{ route('password.change') }}" autocomplete="off">
                    @csrf
                    @method('PUT')
                    <div class="profile-form-grid">
                        <div class="profile-input-group profile-full-width"><label for="current_password">Current Password</label><input class="profile-form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required>@error('current_password')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="password">New Password</label><input class="profile-form-control" type="password" id="password" name="password" minlength="12" autocomplete="new-password" required><small class="profile-field-help">At least 12 characters with uppercase, lowercase, number, and symbol.</small>@error('password')<span class="profile-field-error">{{ $message }}</span>@enderror</div>
                        <div class="profile-input-group"><label for="password_confirmation">Confirm New Password</label><input class="profile-form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" autocomplete="new-password" required></div>
                    </div>
                    <div class="profile-form-actions"><button class="profile-save-button" type="submit"><i class="bi bi-lock" aria-hidden="true"></i> Update Password</button></div>
                </form>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection

@section('scripts')
    @include('partials.profile-copy-script')
@endsection
