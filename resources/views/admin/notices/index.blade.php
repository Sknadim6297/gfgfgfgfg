@extends('layouts.admin')

@section('title', 'Quick PayMoney | Notice Management')

@section('admin-content')
    <div class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">MEMBER COMMUNICATION</span>
            <h1>Notice management</h1>
            <p>Publish announcements that members can read, and choose which ones appear after sign-in.</p>
        </div>
        <a class="admin-button" href="{{ route('admin.notices.create') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create notice</a>
    </div>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">ANNOUNCEMENTS</span><h2>All notices</h2></div></div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Notice</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Popup</th>
                        <th>Schedule</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notices as $notice)
                        <tr>
                            <td>
                                <div class="admin-table-meta">
                                    <strong>{{ $notice->title }}</strong>
                                    <span>{{ $notice->createdBy?->name ?? 'Administrator' }}</span>
                                </div>
                            </td>
                            <td>{{ $notice->typeLabel() }}</td>
                            <td>{{ $notice->priorityLabel() }}</td>
                            <td>{{ $notice->availabilityLabel() }}</td>
                            <td>{{ $notice->popup_enabled ? 'Enabled' : 'Off' }}</td>
                            <td>
                                {{ $notice->publish_at?->timezone(config('app.timezone'))->format('M j, Y H:i') ?? 'Immediate' }}
                                <span class="admin-table-meta">{{ $notice->expires_at ? 'Until '.$notice->expires_at->timezone(config('app.timezone'))->format('M j, Y H:i') : 'No expiry' }}</span>
                            </td>
                            <td>
                                <div class="admin-inline-actions">
                                    <a class="admin-button admin-button-secondary" href="{{ route('admin.notices.edit', $notice) }}">Edit</a>
                                    @if ($notice->status !== 'published')
                                        <form method="POST" action="{{ route('admin.notices.status', $notice) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="published">
                                            <button class="admin-button" type="submit">Activate</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.notices.status', $notice) }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="inactive">
                                            <button class="admin-button admin-button-secondary" type="submit">Deactivate</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.notices.destroy', $notice) }}" data-confirm="This removes the notice for every member. It does not affect wallet or deposit records." data-confirm-title="Delete notice?" data-confirm-button="Delete notice">
                                        @csrf
                                        @method('DELETE')
                                        <button class="admin-button admin-button-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="admin-empty-state">
                                    <span><i class="bi bi-megaphone" aria-hidden="true"></i></span>
                                    <strong>No notices yet</strong>
                                    <p>Create a notice to announce maintenance, warnings, or general information.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($notices->hasPages())<div class="admin-pagination">{{ $notices->links() }}</div>@endif
    </section>
@endsection
