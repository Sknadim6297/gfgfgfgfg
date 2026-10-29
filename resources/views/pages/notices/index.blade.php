@extends('layouts.app')

@section('title', 'Quick PayMoney | Notices')

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
            <a class="profile-back-link" href="{{ route('profile') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Profile</a>
            <section class="profile-content-card">
                <div class="profile-content-heading"><span class="profile-content-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span><div><h2>Notices &amp; Announcements</h2><p>Published updates that are currently available to members.</p></div></div>
                @if ($notices->isEmpty())
                    <div class="profile-empty-state">
                        <i class="bi bi-megaphone" aria-hidden="true"></i>
                        <strong>No notices right now</strong>
                        <p>Published announcements will appear here.</p>
                    </div>
                @else
                    <div class="member-notice-list">
                        @foreach ($notices as $notice)
                            <a class="member-notice-item" href="{{ route('notices.show', $notice) }}">
                                <span class="member-notice-meta">
                                    <span class="member-notice-pill {{ $notice->priority === 'urgent' ? 'is-urgent' : '' }}">{{ $notice->priorityLabel() }}</span>
                                    <span>{{ $notice->typeLabel() }}</span>
                                    <span>{{ $notice->publishedOn()->timezone(config('app.timezone'))->format('M j, Y') }}</span>
                                </span>
                                <strong>{{ $notice->title }}</strong>
                            </a>
                        @endforeach
                    </div>
                    @if ($notices->hasPages())<div class="profile-pagination">{{ $notices->links() }}</div>@endif
                @endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'profile', 'variant' => 'standard'])
    </div>
@endsection
