@extends('layouts.admin')
@section('title', 'Quick PayMoney | Admin Overview')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PLATFORM AT A GLANCE</span><h1>Administration overview</h1><p>Live account and exchange activity from your platform.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.users.index') }}"><i class="bi bi-people" aria-hidden="true"></i> Manage users</a>
    </div>

    <section class="admin-stats" aria-label="Platform statistics">
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span><span class="admin-stat-label">Registered users</span><strong>{{ number_format($metrics['users']) }}</strong><small>All customer accounts</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-person-check-fill" aria-hidden="true"></i></span><span class="admin-stat-label">Active users</span><strong>{{ number_format($metrics['active_users']) }}</strong><small>Accounts with access</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-person-lock" aria-hidden="true"></i></span><span class="admin-stat-label">Suspended / blocked</span><strong>{{ number_format($metrics['suspended_users']) }}</strong><small>Restricted accounts</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-patch-exclamation" aria-hidden="true"></i></span><span class="admin-stat-label">Pending verifications</span><strong>{{ number_format($metrics['pending_verifications']) }}</strong><small>Awaiting review</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><span class="admin-stat-label">Exchange requests</span><strong>{{ number_format($metrics['exchange_requests']) }}</strong><small>Total requests recorded</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><span class="admin-stat-label">Pending / processing</span><strong>{{ number_format($metrics['pending_transactions']) }}</strong><small>Open workflow items</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><span class="admin-stat-label">Completed requests</span><strong>{{ number_format($metrics['completed_transactions']) }}</strong><small>Marked complete</small></article>
        <article class="admin-stat"><span class="admin-stat-icon"><i class="bi bi-x-circle" aria-hidden="true"></i></span><span class="admin-stat-label">Rejected requests</span><strong>{{ number_format($metrics['failed_transactions']) }}</strong><small>Declined in workflow</small></article>
    </section>

    <section class="admin-panel">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">LATEST EVENTS</span><h2>Recent audit activity</h2><p>Administrative actions and recorded account events.</p></div><i class="bi bi-clock-history admin-panel-heading-icon" aria-hidden="true"></i></div>
        <div class="admin-activity-list">
            @forelse ($recentActivity as $activity)
                <article class="admin-activity-item">
                    <span class="admin-activity-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy"><strong>{{ str_replace('_', ' ', $activity->event) }}</strong><span>{{ $activity->actor?->name ?? 'System' }}@if ($activity->subject) <span class="admin-activity-separator">·</span> {{ $activity->subject->name }} @endif</span></div>
                    <time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('M j, Y · H:i') }}</time>
                </article>
            @empty
                <div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No audit activity yet</strong><p>Administrative events will appear here as they are recorded.</p></div>
            @endforelse
        </div>
    </section>
@endsection
