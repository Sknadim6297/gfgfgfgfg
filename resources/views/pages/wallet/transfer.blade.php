@extends('layouts.app')

@section('title', 'Quick PayMoney | Transfer USDT')

@section('font')
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
@endsection

@section('styles')
    <link href="{{ asset('assets/css/index.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/portal.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/profile-dashboard.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/wallet.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
@endsection

@section('content')
    <div class="main-wrapper">
        @include('partials.home-header')
        <main class="content-area app-shell profile-dashboard wallet-area">
            <a class="profile-back-link" href="{{ route('wallet') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to My Wallet</a>
            <h1 class="wallet-title">Transfer USDT</h1>
            <p class="wallet-inline-notice">Send available internal USDT to another Quick PayMoney member. This does not create a blockchain transaction.</p>

            <section class="profile-balance-panel wallet-balances" aria-label="Available USDT balance">
                <article class="profile-balance-card wallet-balance-card">
                    <span class="profile-balance-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
                    <span class="profile-balance-label">Available USDT</span>
                    <strong>{{ \App\Support\Money::formatUsdt($availableBalance) }}</strong>
                </article>
            </section>

            <section class="portal-card wallet-history-card">
                <div class="wallet-section-heading"><h2><i class="bi bi-person-check" aria-hidden="true"></i> Recipient</h2></div>
                @if (! $transfersEnabled)
                    <p class="wallet-inline-notice">Internal USDT transfers are currently unavailable.</p>
                @endif
                <form class="wallet-transfer-form" method="POST" action="{{ route('wallet.transfers.preview') }}">
                    @csrf
                    <label for="lookup-customer-id">Member Customer ID</label>
                    <input class="portal-input" id="lookup-customer-id" name="recipient_customer_id" value="{{ old('recipient_customer_id', $preview['customer_id'] ?? '') }}" maxlength="20" spellcheck="false" autocomplete="off" required>
                    @error('recipient_customer_id')<span class="wallet-field-error">{{ $message }}</span>@enderror
                    <button class="wallet-filter-button" type="submit" @disabled(! $transfersEnabled)><i class="bi bi-search" aria-hidden="true"></i><span>Find member</span></button>
                </form>

                @if (is_array($preview))
                    <div class="wallet-transfer-preview" role="status">
                        <span>Recipient</span>
                        <strong>{{ $preview['name'] }}</strong>
                        <small>{{ $preview['customer_id'] }}</small>
                    </div>
                @endif
            </section>

            <section class="portal-card wallet-history-card">
                <div class="wallet-section-heading"><h2><i class="bi bi-send" aria-hidden="true"></i> Confirm transfer</h2></div>
                @if (! $user->hasWalletTransactionPin())
                    <p class="wallet-inline-notice">Set a <a href="{{ route('profile.wallet-pin') }}">Wallet Transaction PIN</a> before sending USDT.</p>
                @endif
                <form class="wallet-transfer-form" method="POST" action="{{ route('wallet.transfers.store') }}">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
                    <input type="hidden" name="currency" value="USDT">
                    <label for="transfer-customer-id">Member Customer ID</label>
                    <input class="portal-input" id="transfer-customer-id" name="recipient_customer_id" value="{{ old('recipient_customer_id', $preview['customer_id'] ?? '') }}" maxlength="20" spellcheck="false" autocomplete="off" required>
                    <label for="transfer-amount">Amount (USDT)</label>
                    <input class="portal-input" id="transfer-amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" maxlength="21" autocomplete="off" required>
                    @error('amount')<span class="wallet-field-error">{{ $message }}</span>@enderror
                    @if ($settings)
                        <p class="wallet-inline-notice">Limits: {{ $settings->minimum_amount }}@if ($settings->maximum_amount) to {{ $settings->maximum_amount }}@endif USDT. Pending deposits, demo USDT, and INR cannot be transferred.</p>
                    @endif
                    <label for="transfer-pin">Wallet Transaction PIN</label>
                    <input class="portal-input" id="transfer-pin" name="wallet_transaction_pin" type="password" inputmode="numeric" maxlength="4" autocomplete="off" required>
                    @error('wallet_transaction_pin')<span class="wallet-field-error">{{ $message }}</span>@enderror
                    @error('submission_key')<span class="wallet-field-error">{{ $message }}</span>@enderror
                    <button class="wallet-filter-button" type="submit" @disabled(! $transfersEnabled || ! $user->hasWalletTransactionPin())><i class="bi bi-check2" aria-hidden="true"></i><span>Send USDT</span></button>
                </form>
            </section>

            <section class="portal-card wallet-history-card" id="transfer-history">
                <div class="wallet-section-heading"><h2><i class="bi bi-clock-history" aria-hidden="true"></i> Transfer history</h2></div>
                @forelse ($history as $transfer)
                    @php $outgoing = $transfer->sender_user_id === $user->id; @endphp
                    <article class="wallet-transfer-row">
                        <div>
                            <strong>{{ $outgoing ? 'Sent' : 'Received' }} · {{ $transfer->transfer_reference }}</strong>
                            <span>{{ $outgoing ? $transfer->recipient?->name : $transfer->sender?->name }} · {{ $outgoing ? $transfer->recipient?->customer_id : $transfer->sender?->customer_id }}</span>
                        </div>
                        <div>
                            <b>{{ $outgoing ? '-' : '+' }}{{ \App\Support\Money::formatUsdt((string) $transfer->amount) }} USDT</b>
                            <small>{{ ucfirst($transfer->status) }} · {{ $transfer->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</small>
                        </div>
                    </article>
                @empty
                    <p class="wallet-inline-notice">No internal transfers yet.</p>
                @endforelse
                @if ($history->hasPages())
                    <div class="support-pagination">{{ $history->links() }}</div>
                @endif
            </section>
        </main>
        @include('partials.bottom-nav', ['active' => 'wallet', 'variant' => 'wallet', 'appShell' => true])
    </div>
@endsection
