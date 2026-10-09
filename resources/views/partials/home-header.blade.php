<header class="top-header">
    <div class="header-inner">
        <a href="{{ route('home') }}" class="text-decoration-none">
            <div class="logo">Quick Pay<span class="logo-x">Money</span></div>
        </a>

        <div class="header-actions">
            <a class="header-btn icon-only {{ request()->routeIs('home') ? '' : 'header-contact' }}" href="{{ route('contact') }}" aria-label="Contact support">
                <i class="bi bi-headset" aria-hidden="true"></i>
            </a>

            @auth('web')
                <a class="header-btn wallet-header-link" href="{{ route('wallet') }}" aria-label="My Wallet">
                    <i class="bi bi-wallet2" aria-hidden="true"></i>
                </a>
            @else
                <a class="header-btn login-btn" href="{{ route('login') }}">
                    <i class="bi bi-person-fill" aria-hidden="true"></i>
                    <span>Login</span>
                </a>
            @endauth
        </div>
    </div>
</header>
