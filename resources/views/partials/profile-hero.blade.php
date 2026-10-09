<section class="profile-hero-card" aria-label="Customer profile">
    <div class="profile-hero-identity">
        <span class="profile-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
        <div class="profile-hero-text">
            <h1 title="{{ $user->name }}">{{ $user->name }}</h1>
            <div class="profile-customer-id">
                <span>ID: <strong>{{ $user->customer_id }}</strong></span>
                <button type="button" class="profile-copy-button" data-copy-value="{{ $user->customer_id }}" aria-label="Copy customer ID">
                    <i class="bi bi-copy" aria-hidden="true"></i><span class="profile-copy-feedback" data-copy-feedback aria-live="polite"></span>
                </button>
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="profile-logout-button" type="submit" aria-label="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Logout</span></button>
    </form>
</section>
