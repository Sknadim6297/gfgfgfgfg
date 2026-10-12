@extends('layouts.admin')

@section('title', 'Quick PayMoney | Push Notifications')

@section('admin-content')
    <div class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">CUSTOMER COMMUNICATIONS</span>
            <h1>Push notifications</h1>
            <p>Send a short update to subscribed customer devices and review campaign processing.</p>
        </div>
        <span class="admin-record-count"><i class="bi bi-bell" aria-hidden="true"></i> {{ $customers->count() }} subscribed customers</span>
    </div>

    <p class="admin-summary-note">All-time campaign counts; the campaign history pagination does not affect these totals.</p>
    <section class="admin-stats admin-user-stats" aria-label="All-time push campaign summary">
        <article class="admin-stat"><span class="admin-stat-label">Total campaigns</span><strong>{{ number_format((int) $summary->total_count) }}</strong><small>All recorded campaigns</small></article>
        <article class="admin-stat"><span class="admin-stat-label">Queued / processing</span><strong>{{ number_format((int) ($summary->in_progress_count ?? 0)) }}</strong><small>Awaiting or undergoing delivery</small></article>
        <article class="admin-stat"><span class="admin-stat-label">Completed</span><strong>{{ number_format((int) ($summary->completed_count ?? 0)) }}</strong><small>Includes completed with failures</small></article>
        <article class="admin-stat"><span class="admin-stat-label">Failed</span><strong>{{ number_format((int) ($summary->failed_count ?? 0)) }}</strong><small>Campaign processing failed</small></article>
    </section>

    <section class="admin-panel" aria-labelledby="push-notification-form-title">
        <div class="admin-panel-heading">
            <div><span class="admin-eyebrow">NEW CAMPAIGN</span><h2 id="push-notification-form-title">Compose notification</h2><p>Delivery is queued and processed by background workers.</p></div>
        </div>
        @if ($customers->isEmpty())
            <div class="admin-inline-notice" role="status">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <p>No active customers have subscribed to push notifications yet. The queue button will become available after a customer enables notifications.</p>
            </div>
        @endif
        <form class="admin-form" method="POST" action="{{ route('admin.push-notifications.store') }}">
            @csrf
            <div class="admin-form-columns admin-push-form-grid">
                <div class="admin-field">
                    <label for="push-title">Notification title</label>
                    <input class="portal-input" id="push-title" name="title" value="{{ old('title') }}" maxlength="80" required>
                    @error('title')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div class="admin-field">
                    <label for="push-audience">Recipient</label>
                    <select class="portal-input" id="push-audience" name="audience" required>
                        <option value="all_subscribed" @selected(old('audience', 'all_subscribed') === 'all_subscribed')>All subscribed customers</option>
                        <option value="specific_user" @selected(old('audience') === 'specific_user')>Specific customer</option>
                    </select>
                    @error('audience')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div class="admin-field" data-push-target-field @if (old('audience') !== 'specific_user') hidden @endif>
                    <label for="push-target-user">Customer</label>
                    <select class="portal-input" id="push-target-user" name="target_user_id">
                        <option value="">Select a subscribed customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((string) old('target_user_id') === (string) $customer->id)>{{ $customer->name }} — {{ $customer->email }}</option>
                        @endforeach
                    </select>
                    @error('target_user_id')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div class="admin-field">
                    <label for="push-destination">Destination URL (optional)</label>
                    <input class="portal-input" id="push-destination" name="destination_url" value="{{ old('destination_url') }}" maxlength="2000" placeholder="/profile">
                    <small>Use a path beginning with / or a URL on this website.</small>
                    @error('destination_url')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
                <div class="admin-field admin-field-full">
                    <label for="push-message">Notification message</label>
                    <textarea class="portal-input" id="push-message" name="message" rows="4" maxlength="300" required>{{ old('message') }}</textarea>
                    <small>Keep the notification generic. Do not include personal, financial, bank, balance, or transaction details.</small>
                    @error('message')<span class="admin-field-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <button class="admin-button" type="submit" @disabled($customers->isEmpty()) aria-describedby="push-queue-help"><i class="bi bi-send" aria-hidden="true"></i> Queue notification</button>
            @if ($customers->isEmpty())
                <small id="push-queue-help">At least one active customer with an enabled device is required.</small>
            @endif
        </form>
    </section>

    <section class="admin-panel admin-directory" aria-labelledby="push-campaign-history-title">
        <div class="admin-panel-heading">
            <div><span class="admin-eyebrow">AUDIT HISTORY</span><h2 id="push-campaign-history-title">Notification campaigns</h2><p>“Accepted” means Firebase accepted the request; it does not confirm a device displayed it.</p></div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th scope="col">Campaign</th><th scope="col">Recipient</th><th scope="col">Status</th><th scope="col">Accepted</th><th scope="col">Failed</th><th scope="col">Pending</th><th scope="col">Created / completed</th></tr></thead>
                <tbody>
                @forelse ($campaigns as $campaign)
                    <tr>
                        <td><strong>{{ $campaign->title }}</strong><small>{{ $campaign->message }}</small></td>
                        <td>{{ $campaign->audience === 'specific_user' ? ($campaign->targetUser?->name ?? 'Customer removed') : 'All subscribed customers' }}<small>{{ number_format($campaign->recipient_count) }} device(s)</small></td>
                        <td><span class="portal-badge {{ in_array($campaign->status, ['completed', 'completed_with_failures'], true) ? ($campaign->status === 'completed' ? 'verified' : 'rejected') : 'pending' }}"><span class="admin-badge-dot"></span>{{ str($campaign->status)->replace('_', ' ')->title() }}</span></td>
                        <td>{{ number_format($campaign->accepted_count) }}</td>
                        <td>{{ number_format($campaign->failed_count) }}</td>
                        <td>{{ number_format($campaign->pending_count) }}</td>
                        <td><time datetime="{{ $campaign->created_at->toIso8601String() }}">{{ $campaign->created_at->format('M j, Y H:i') }}</time><small>{{ $campaign->completed_at?->format('M j, Y H:i') ?? 'In progress' }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="admin-empty-state"><span><i class="bi bi-bell-slash" aria-hidden="true"></i></span><strong>No notification campaigns</strong><p>Queued campaigns will appear here with processing counts.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($campaigns->hasPages())<div class="admin-pagination">{{ $campaigns->links() }}</div>@endif
    </section>
    <script>
        (() => {
            const audience = document.getElementById('push-audience');
            const targetField = document.querySelector('[data-push-target-field]');
            const target = document.getElementById('push-target-user');
            if (!audience || !targetField || !target) return;
            const syncTarget = () => {
                const specific = audience.value === 'specific_user';
                targetField.hidden = !specific;
                target.required = specific;
            };
            audience.addEventListener('change', syncTarget);
            syncTarget();
        })();
    </script>
@endsection
