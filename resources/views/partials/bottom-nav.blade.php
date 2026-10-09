@if ($variant === 'wallet')
    <nav class="bottom-nav {{ !empty($appShell) ? 'app-shell' : '' }}">
        <a href="{{ route('home') }}" class="nav-item {{ $active === 'home' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-house-fill"></i></div>
            <span>Home</span>
        </a>
        <a href="{{ route('exchange') }}" class="nav-item {{ $active === 'exchange' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-arrow-left-right"></i></div>
            <span>Exchange</span>
        </a>
        <a href="{{ route('wallet') }}" class="nav-item {{ $active === 'wallet' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-wallet2"></i></div>
            <span>Wallet</span>
        </a>
        <a href="{{ route('profile') }}" class="nav-item {{ $active === 'profile' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-person-fill"></i></div>
            <span>Profile</span>
        </a>
    </nav>
@elseif ($variant === 'exchange')
    <nav class="bottom-nav {{ !empty($appShell) ? 'app-shell' : '' }}">
        <a href="{{ route('home') }}" class="nav-item {{ $active === 'home' ? 'active' : '' }}">
            <i class="bi bi-house-fill"></i>
            <span>Home</span>
        </a>
        <a href="{{ route('exchange') }}" class="nav-item {{ $active === 'exchange' ? 'active' : '' }}">
            <i class="bi bi-arrow-left-right"></i>
            <span>Exchange</span>
        </a>
        <a href="{{ route('profile') }}" class="nav-item {{ $active === 'profile' ? 'active' : '' }}">
            <i class="bi bi-person-fill"></i>
            <span>Profile</span>
        </a>
    </nav>
@else
    <nav class="bottom-nav {{ !empty($appShell) ? 'app-shell' : '' }}">
        <a href="{{ route('home') }}" class="nav-item {{ $active === 'home' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-house-fill"></i></div>
            <span>Home</span>
        </a>
        <a href="{{ route('exchange') }}" class="nav-item {{ $active === 'exchange' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-arrow-left-right"></i></div>
            <span>Exchange</span>
        </a>
        <a href="{{ route('profile') }}" class="nav-item {{ $active === 'profile' ? 'active' : '' }}">
            <div class="nav-icon"><i class="bi bi-person-fill"></i></div>
            <span>Profile</span>
        </a>
    </nav>
@endif
