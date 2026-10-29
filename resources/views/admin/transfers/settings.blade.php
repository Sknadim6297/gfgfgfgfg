@extends('layouts.admin')
@section('title', 'Quick PayMoney | Transfer Limits')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">WALLET CONTROLS</span><h1>Transfer limits</h1><p>Set the internal USDT transfer window. Completed ledger entries cannot be edited here.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.internal-transfers.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Transfer history</a>
    </div>
    <section class="admin-panel">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">LIMITS</span><h2>USDT transfer settings</h2></div></div>
        <form class="admin-form" method="POST" action="{{ route('admin.internal-transfers.settings.update') }}">
            @csrf
            @method('PUT')
            <label for="minimum_amount">Minimum transfer (USDT)</label>
            <input class="portal-input" id="minimum_amount" name="minimum_amount" inputmode="decimal" value="{{ old('minimum_amount', $settings?->minimum_amount ?? '1.00000000') }}" maxlength="21" required>
            @error('minimum_amount')<span class="admin-field-error">{{ $message }}</span>@enderror
            <label for="maximum_amount">Maximum transfer (USDT)</label>
            <input class="portal-input" id="maximum_amount" name="maximum_amount" inputmode="decimal" value="{{ old('maximum_amount', $settings?->maximum_amount) }}" maxlength="21" placeholder="Leave blank for no maximum">
            @error('maximum_amount')<span class="admin-field-error">{{ $message }}</span>@enderror
            <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked((bool) old('is_enabled', $settings?->is_enabled))> Enable member USDT transfers</label>
            @error('is_enabled')<span class="admin-field-error">{{ $message }}</span>@enderror
            <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save transfer settings</button>
        </form>
    </section>
    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Settings history</h2></div></div>
        <div class="admin-activity-list">
            @forelse ($history as $entry)
                <article class="admin-activity-item"><span class="admin-activity-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><div class="admin-activity-copy"><strong>{{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong><span>{{ $entry->actor?->name ?? 'Administrator' }} · {{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</span></div></article>
            @empty
                <div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No settings changes recorded</strong><p>Future limit updates will be recorded here.</p></div>
            @endforelse
        </div>
    </section>
@endsection
