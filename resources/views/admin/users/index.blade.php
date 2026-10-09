@extends('layouts.admin')
@section('title', 'Quick PayMoney | User Management')
@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">ACCOUNT DIRECTORY</span><h1>User management</h1><p>Search accounts and review account status, bank details, and access.</p></div>
        <span class="admin-record-count"><i class="bi bi-people-fill" aria-hidden="true"></i> {{ number_format($stats['total']) }} accounts</span>
    </div>

    <section class="admin-stats admin-user-stats" aria-label="User account statistics">
        <article class="admin-stat"><span class="admin-stat-label">All accounts</span><strong>{{ number_format($stats['total']) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Active</span><strong>{{ number_format($stats['active']) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Suspended</span><strong>{{ number_format($stats['suspended']) }}</strong></article>
        <article class="admin-stat"><span class="admin-stat-label">Blocked</span><strong>{{ number_format($stats['blocked']) }}</strong></article>
    </section>

    <section class="admin-panel admin-directory">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">PEOPLE</span><h2>All accounts</h2><p>Use search and filters to find an account.</p></div></div>
        <form class="admin-filters" method="GET" action="{{ route('admin.users.index') }}">
            <div class="admin-search-field"><label class="visually-hidden" for="search">Search name, email or mobile</label><i class="bi bi-search" aria-hidden="true"></i><input class="portal-input" id="search" name="search" value="{{ request('search') }}" maxlength="120" placeholder="Search name, email, or mobile"></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="status">Account status</label><select class="portal-input" id="status" name="status"><option value="">All account statuses</option>@foreach (['active', 'suspended', 'blocked'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <div class="admin-filter-select"><label class="visually-hidden" for="bank-status">Bank verification</label><select class="portal-input" id="bank-status" name="bank_status"><option value="">All bank statuses</option>@foreach (\App\Models\User::BANK_VERIFICATION_STATUSES as $bankStatus)<option value="{{ $bankStatus }}" @selected(request('bank_status') === $bankStatus)>{{ str($bankStatus)->replace('_', ' ')->title() }}</option>@endforeach</select><i class="bi bi-chevron-down" aria-hidden="true"></i></div>
            <button class="admin-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
            @if (request()->hasAny(['search', 'status', 'bank_status']))<a class="admin-button admin-button-secondary" href="{{ route('admin.users.index') }}">Clear</a>@endif
        </form>
        <div class="admin-table-meta"><span>Showing <strong>{{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }}</strong> of <strong>{{ $users->total() }}</strong> accounts</span><span>Account directory</span></div>
        <div class="portal-table-wrap admin-table-wrap">
            <table class="portal-table admin-table">
                <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Registered</th><th scope="col">Bank</th><th scope="col">Account status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td><div class="admin-user-cell"><span class="admin-avatar admin-avatar-table" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span><span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span></div></td>
                        <td><span class="admin-role-label">{{ ucfirst($user->role) }}</span></td>
                        <td><time datetime="{{ $user->created_at->toDateString() }}">{{ $user->created_at->format('M j, Y') }}</time></td>
                        @php($bankStatus = $user->bankVerificationStatusForAdminDirectory())
                        <td><span class="portal-badge {{ $bankStatus === 'not_submitted' ? 'inactive' : $bankStatus }}"><span class="admin-badge-dot"></span>{{ str($bankStatus)->replace('_', ' ')->title() }}</span></td>
                        <td><span class="portal-badge {{ $user->account_status }}"><span class="admin-badge-dot"></span>{{ ucfirst($user->account_status) }}</span></td>
                        <td class="admin-table-action"><a class="admin-icon-button" href="{{ route('admin.users.show', $user) }}" aria-label="View {{ $user->name }} details"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="admin-empty-state"><span><i class="bi bi-search" aria-hidden="true"></i></span><strong>No accounts found</strong><p>Try changing the search term or clearing the filters.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())<div class="admin-pagination">{{ $users->links() }}</div>@endif
    </section>
@endsection
