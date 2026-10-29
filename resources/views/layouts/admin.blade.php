@extends('layouts.app')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/admin.css') }}" rel="stylesheet">
@endsection

@section('content')
    @php
        $adminPageTitle = match (true) {
            request()->routeIs('admin.dashboard') => 'Overview',
            request()->routeIs('admin.demo-preview.*') => 'Demo data preview',
            request()->routeIs('admin.users.show') => 'User details',
            request()->routeIs('admin.users.index') => 'User management',
            request()->routeIs('admin.exchanges.show') => 'Sell request details',
            request()->routeIs('admin.exchanges.index') => 'Sell request management',
            request()->routeIs('admin.deposits.show') => 'Deposit review',
            request()->routeIs('admin.deposits.index') => 'Deposit management',
            request()->routeIs('admin.withdrawals.show') => 'Withdrawal review',
            request()->routeIs('admin.withdrawals.index') => 'Withdrawal management',
            request()->routeIs('admin.support-tickets.show') => 'Support ticket',
            request()->routeIs('admin.support-tickets.index') => 'Support tickets',
            request()->routeIs('admin.push-notifications.*') => 'Push notifications',
            request()->routeIs('admin.notices.*') => 'Notice management',
            request()->routeIs('admin.referrals.commissions') => 'Referral commission report',
            request()->routeIs('admin.referrals.*') => 'Referral management',
            request()->routeIs('admin.deposit-settings.*') => 'Deposit settings',
            request()->routeIs('admin.rates.*') => 'Exchange rates',
            request()->routeIs('admin.profile*') => 'Admin profile',
            default => 'Administration',
        };
    @endphp
    <div class="portal-wrapper admin-wrapper admin-shell">
        <button class="admin-sidebar-scrim" type="button" aria-label="Close navigation" data-admin-sidebar-close hidden></button>
        <aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin navigation">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand" aria-label="Quick PayMoney admin overview">
                <span class="logo">Quick Pay<span class="logo-x">Money</span></span>
                <span class="admin-brand-caption">ADMIN CONSOLE</span>
            </a>
            <nav class="admin-side-links" aria-label="Admin sidebar">
                <span class="admin-nav-label">WORKSPACE</span>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i><span>Overview</span></a>
                <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users*') ? 'active' : '' }}" @if (request()->routeIs('admin.users*')) aria-current="page" @endif><i class="bi bi-people-fill" aria-hidden="true"></i><span>Users</span></a>
                <a href="{{ route('admin.exchanges.index') }}" class="{{ request()->routeIs('admin.exchanges*') ? 'active' : '' }}" @if (request()->routeIs('admin.exchanges*')) aria-current="page" @endif><i class="bi bi-arrow-left-right" aria-hidden="true"></i><span>Sell Requests</span></a>
                <a href="{{ route('admin.deposits.index') }}" class="{{ request()->routeIs('admin.deposits*') ? 'active' : '' }}" @if (request()->routeIs('admin.deposits*')) aria-current="page" @endif><i class="bi bi-wallet2" aria-hidden="true"></i><span>Deposits</span></a>
                <a href="{{ route('admin.withdrawals.index') }}" class="{{ request()->routeIs('admin.withdrawals*') ? 'active' : '' }}" @if (request()->routeIs('admin.withdrawals*')) aria-current="page" @endif><i class="bi bi-bank" aria-hidden="true"></i><span>Withdrawals</span></a>
                <a href="{{ route('admin.support-tickets.index') }}" class="{{ request()->routeIs('admin.support-tickets*') ? 'active' : '' }}" @if (request()->routeIs('admin.support-tickets*')) aria-current="page" @endif><i class="bi bi-headset" aria-hidden="true"></i><span>Support Tickets</span></a>
                <a href="{{ route('admin.push-notifications.index') }}" class="{{ request()->routeIs('admin.push-notifications.*') ? 'active' : '' }}" @if (request()->routeIs('admin.push-notifications.*')) aria-current="page" @endif><i class="bi bi-bell" aria-hidden="true"></i><span>Push Notifications</span></a>
                <a href="{{ route('admin.notices.index') }}" class="{{ request()->routeIs('admin.notices.*') ? 'active' : '' }}" @if (request()->routeIs('admin.notices.*')) aria-current="page" @endif><i class="bi bi-megaphone" aria-hidden="true"></i><span>Notice Management</span></a>
                <a href="{{ route('admin.referrals.settings') }}" class="{{ request()->routeIs('admin.referrals.*') ? 'active' : '' }}" @if (request()->routeIs('admin.referrals.*')) aria-current="page" @endif><i class="bi bi-diagram-3" aria-hidden="true"></i><span>Referral Management</span></a>
                <a href="{{ route('admin.deposit-settings.edit') }}" class="{{ request()->routeIs('admin.deposit-settings.*') ? 'active' : '' }}" @if (request()->routeIs('admin.deposit-settings.*')) aria-current="page" @endif><i class="bi bi-qr-code" aria-hidden="true"></i><span>Deposit settings</span></a>
                <a href="{{ route('admin.rates.edit') }}" class="{{ request()->routeIs('admin.rates*') ? 'active' : '' }}" @if (request()->routeIs('admin.rates*')) aria-current="page" @endif><i class="bi bi-currency-exchange" aria-hidden="true"></i><span>Exchange rates</span></a>

                <span class="admin-nav-label admin-nav-label-account">ACCOUNT</span>
                <a href="{{ route('admin.profile') }}" class="{{ request()->routeIs('admin.profile*') ? 'active' : '' }}" @if (request()->routeIs('admin.profile*')) aria-current="page" @endif><i class="bi bi-shield-lock-fill" aria-hidden="true"></i><span>Admin profile</span></a>
            </nav>
            <div class="admin-sidebar-footer">
                <div class="admin-sidebar-user">
                    <span class="admin-avatar" aria-hidden="true">{{ mb_substr(auth('admin')->user()->name, 0, 1) }}</span>
                    <span class="admin-sidebar-user-copy"><strong>{{ auth('admin')->user()->name }}</strong><small>Administrator</small></span>
                </div>
                <form method="POST" action="{{ route('admin.logout') }}" data-confirm="You will be signed out of the admin panel." data-confirm-title="Sign out?">
                    @csrf
                    <button class="admin-logout-link" type="submit"><i class="bi bi-box-arrow-left" aria-hidden="true"></i><span>Logout</span></button>
                </form>
            </div>
        </aside>
        <div class="admin-workspace">
            <header class="admin-topbar">
                <div class="admin-topbar-leading">
                    <button class="admin-menu-toggle" type="button" aria-label="Open navigation" aria-controls="admin-sidebar" aria-expanded="false" data-admin-sidebar-toggle><i class="bi bi-list" aria-hidden="true"></i></button>
                    <div class="admin-breadcrumb">
                        <span>Quick PayMoney</span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong>{{ $adminPageTitle }}</strong>
                    </div>
                </div>
                <div class="admin-topbar-actions">
                    <span class="admin-secure-indicator"><i class="bi bi-shield-check" aria-hidden="true"></i> Secure admin session</span>
                    <details class="admin-profile-menu">
                        <summary aria-label="Administrator profile menu">
                            <span class="admin-avatar">{{ mb_substr(auth('admin')->user()->name, 0, 1) }}</span>
                            <span class="admin-profile-menu-copy"><strong>{{ auth('admin')->user()->name }}</strong><small>Administrator</small></span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </summary>
                        <div class="admin-profile-dropdown">
                            <span class="admin-dropdown-email">{{ auth('admin')->user()->email }}</span>
                            <a href="{{ route('admin.profile') }}"><i class="bi bi-person-gear" aria-hidden="true"></i> Profile &amp; security</a>
                            <form method="POST" action="{{ route('admin.logout') }}" data-confirm="You will be signed out of the admin panel." data-confirm-title="Sign out?">
                                @csrf
                                <button type="submit"><i class="bi bi-box-arrow-left" aria-hidden="true"></i> Logout</button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>
            <main class="portal-container admin-content">
                @yield('admin-content')
            </main>
        </div>
    </div>
    <script>
        (() => {
            const sidebar = document.getElementById('admin-sidebar');
            const toggle = document.querySelector('[data-admin-sidebar-toggle]');
            const close = document.querySelector('[data-admin-sidebar-close]');
            if (!sidebar || !toggle || !close) return;
            const setOpen = (open) => {
                sidebar.classList.toggle('is-open', open);
                close.hidden = !open;
                toggle.setAttribute('aria-expanded', String(open));
                toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
                document.body.classList.toggle('admin-nav-open', open);
                if (open) sidebar.querySelector('a')?.focus();
            };
            toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
            close.addEventListener('click', () => {
                setOpen(false);
                toggle.focus();
            });
            sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                    setOpen(false);
                    toggle.focus();
                }
            });
        })();
    </script>
@endsection
