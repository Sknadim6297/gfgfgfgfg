@extends('layouts.app')

@section('title', 'Quick PayMoney | Notice')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/member-notices.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area profile-dashboard profile-subpage">
            @include('partials.profile-hero', ['user' => $user])
            <a class="profile-back-link" href="{{ route('notices.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> All notices</a>
            <section class="profile-content-card member-notice-detail">
                <span class="member-notice-meta">
                    <span class="member-notice-pill {{ in_array($notice->priority, ['urgent', 'high'], true) || $notice->type === 'warning' ? 'is-urgent' : '' }}">{{ $notice->priorityLabel() }}</span>
                    <span>{{ $notice->typeLabel() }}</span>
                </span>
                <h2>{{ $notice->title }}</h2>
                <p class="member-notice-date">Published {{ $notice->publishedOn()->timezone(config('app.timezone'))->format('M j, Y') }}</p>
                <p class="member-notice-copy">{{ $notice->description }}</p>
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection
