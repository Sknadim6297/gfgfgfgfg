<header class="top-header">
    <div class="header-inner">
        <a href="{{ route('home') }}" class="text-decoration-none">
            <div class="logo">
                Quick Pay<span class="logo-x">Money</span>
            </div>
        </a>

        <div class="header-actions">
            <a href="{{ route($actionRoute) }}" class="{{ $actionClass }}">
                <i class="bi bi-person-fill"></i>
                {{ $actionLabel }}
            </a>

            @if ($showContact)
                <button type="button" class="header-btn icon-only"
                        onclick="window.location.href='{{ route('contact') }}'">
                    <i class="bi bi-headset"></i>
                </button>
            @endif
        </div>
    </div>
</header>
