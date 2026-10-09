@extends('layouts.admin')

@section('title', 'Quick PayMoney | Referral Management')

@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">CUSTOMER GROWTH</span><h1>Referral Management</h1><p>Configure the three referral commission levels and review every change. Updates apply only to future qualifications.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.referrals.commissions') }}"><i class="bi bi-journal-text" aria-hidden="true"></i> Commission report</a>
    </div>

    <section class="admin-stats admin-user-stats" aria-label="Referral commission summary">
        <article class="admin-stat"><span class="admin-stat-label">Total commissions paid</span><strong>{{ \App\Support\Money::formatInr($totalPaid) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Qualified referrals</span><strong>{{ number_format($qualifiedReferrals) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Active levels</span><strong>{{ number_format($activeLevels) }} / 3</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Inactive levels</span><strong>{{ number_format($inactiveLevels) }} / 3</strong></article>
    </section>

    <section class="admin-stats admin-user-stats" aria-label="Paid commissions by referral level">
        @foreach ([1 => 'Level 1 paid', 2 => 'Level 2 paid', 3 => 'Level 3 paid'] as $levelNumber => $label)
            <article class="admin-stat"><span class="admin-stat-label">{{ $label }}</span><strong>{{ \App\Support\Money::formatInr((string) ($levelTotals[$levelNumber] ?? '0.00')) }}</strong></article>
        @endforeach
    </section>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">COMMISSION CONFIGURATION</span><h2>Referral commission settings</h2><p>Exactly three fixed levels are available. Deactivation preserves settings and existing financial history.</p></div></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th scope="col">Level</th><th scope="col">Default</th><th scope="col">Commission amount</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @foreach ($levels as $level)
                        <tr>
                            <td><strong>Level {{ $level->level }}</strong></td>
                            <td>{{ \App\Support\Money::formatInr([1 => '1500.00', 2 => '1000.00', 3 => '500.00'][$level->level]) }}</td>
                            <td>
                                <form class="admin-form" method="POST" action="{{ route('admin.referrals.levels.update', $level) }}">
                                    @csrf @method('PUT')
                                    <label class="visually-hidden" for="level-amount-{{ $level->level }}">Level {{ $level->level }} amount in INR</label>
                                    <input class="portal-input" id="level-amount-{{ $level->level }}" name="amount_{{ $level->level }}" type="number" min="0.01" max="999999999999999999.99" step="0.01" value="{{ old('amount_'.$level->level, $level->amount) }}" required>
                                    @error('amount_'.$level->level)<span class="admin-field-error">{{ $message }}</span>@enderror
                                    <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save amount</button>
                                </form>
                            </td>
                            <td><span class="portal-badge {{ $level->is_active ? 'verified' : 'rejected' }}"><span class="admin-badge-dot"></span>{{ $level->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admin.referrals.levels.status', $level) }}" data-confirm="{{ $level->is_active ? 'Deactivate this level for future referral qualifications? Existing commissions and ledger entries will remain unchanged.' : 'Restore this level for future referral qualifications? Existing commissions and ledger entries will remain unchanged.' }}" data-confirm-title="{{ $level->is_active ? 'Deactivate level?' : 'Restore level?' }}" data-confirm-button="{{ $level->is_active ? 'Deactivate' : 'Restore' }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $level->is_active ? '0' : '1' }}">
                                    <button class="admin-button {{ $level->is_active ? 'admin-button-danger' : 'admin-button-secondary' }}" type="submit"><i class="bi {{ $level->is_active ? 'bi-pause-circle' : 'bi-arrow-counterclockwise' }}" aria-hidden="true"></i> {{ $level->is_active ? 'Deactivate' : 'Restore' }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT HISTORY</span><h2>Configuration changes</h2><p>Recent amount and activation changes, including the administrator and before/after settings.</p></div></div>
        <div class="admin-activity-list">
            @forelse ($history as $entry)
                @php($before = $entry->metadata['before'] ?? [])
                @php($after = $entry->metadata['after'] ?? [])
                <article class="admin-activity-item">
                    <span class="admin-activity-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                    <div class="admin-activity-copy">
                        <strong>Level {{ $after['level'] ?? $before['level'] ?? '—' }} · {{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong>
                        <span>{{ $entry->actor?->name ?? 'Administrator' }} · {{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</span>
                        <span>{{ isset($before['amount']) ? \App\Support\Money::formatInr((string) $before['amount']) : '—' }} / {{ ($before['is_active'] ?? false) ? 'Active' : 'Inactive' }} → {{ isset($after['amount']) ? \App\Support\Money::formatInr((string) $after['amount']) : '—' }} / {{ ($after['is_active'] ?? false) ? 'Active' : 'Inactive' }}</span>
                    </div>
                </article>
            @empty
                <div class="admin-empty-state"><span><i class="bi bi-clock-history" aria-hidden="true"></i></span><strong>No configuration changes recorded</strong><p>Updates and status changes will be recorded here.</p></div>
            @endforelse
        </div>
    </section>
@endsection
