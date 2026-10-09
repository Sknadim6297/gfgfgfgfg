@extends('layouts.app')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/admin-auth.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="admin-auth-shell">
        <header class="admin-auth-header">
            <a href="{{ route('landing') }}" class="admin-auth-brand" aria-label="Quick PayMoney home">
                <span class="logo">Quick Pay<span class="logo-x">Money</span></span>
            </a>
            <span class="admin-auth-secure"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> SECURE ADMIN PORTAL</span>
        </header>
        <main class="admin-auth-main">
            <section class="admin-auth-card">
                <div class="admin-auth-mark" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
                <div class="admin-auth-heading">
                    <span class="admin-auth-eyebrow">QUICK PAYMONEY ADMIN</span>
                    @yield('auth-heading')
                </div>
                @yield('auth-content')
            </section>
            <p class="admin-auth-footnote"><i class="bi bi-lock-fill" aria-hidden="true"></i> Protected access · Administrator accounts only</p>
        </main>
    </div>
@endsection
