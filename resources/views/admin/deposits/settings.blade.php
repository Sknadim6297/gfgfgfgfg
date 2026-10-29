@extends('layouts.admin')

@section('title', 'Quick PayMoney | Deposit Settings')

@section('styles')
    @parent
    <link href="{{ asset('assets/css/deposit.css') }}" rel="stylesheet">
@endsection

@section('admin-content')
    <div class="admin-page-heading">
        <div><span class="admin-eyebrow">PAYMENT CONFIGURATION</span><h1>Deposit settings</h1><p>Configure BEP20 deposit details and securely stored network images.</p></div>
        <a class="admin-button admin-button-secondary" href="{{ route('admin.deposits.index') }}"><i class="bi bi-inbox" aria-hidden="true"></i> Deposit requests</a>
    </div>

    <div class="admin-detail-grid">
        <section class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">PAYMENT DETAILS</span><h2>Customer instructions</h2><p>Changes are recorded in the administrative audit log.</p></div></div>
            <form class="admin-form" id="deposit-settings-form" method="POST" action="{{ route('admin.deposit-settings.update') }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <label for="network_name">Network</label>
                <select class="portal-input" id="network_name" name="network_name">
                    <option value="BEP20" @selected(old('network_name', $settings?->network_name ?? 'BEP20') === 'BEP20')>BEP20</option>
                </select>
                @error('network_name')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="blockchain_display">Blockchain</label>
                <input class="portal-input" id="blockchain_display" value="BEP20 mainnet" readonly>

                <label for="token_contract_address">USDT token contract address</label>
                <input class="portal-input" id="token_contract_address" name="token_contract_address" value="{{ old('token_contract_address', $settings?->token_contract_address) }}" maxlength="42" spellcheck="false" autocomplete="off">
                @error('token_contract_address')<span class="admin-field-error">{{ $message }}</span>@enderror
                <p class="admin-field-hint">Enter the BEP20 USDT contract address. To enable deposits, it must match the server's <code>BEP20_USDT_CONTRACT_ADDRESS</code> configuration. Address letter casing is ignored. Never use an unverified contract.</p>

                <label for="receiving_wallet_address">Receiving wallet address</label>
                <input class="portal-input" id="receiving_wallet_address" name="receiving_wallet_address" value="{{ old('receiving_wallet_address', $settings?->receiving_wallet_address) }}" maxlength="42" spellcheck="false" autocomplete="off">
                @error('receiving_wallet_address')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="recipient_name">Payment recipient name</label>
                <input class="portal-input" id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $settings?->recipient_name) }}" maxlength="120">
                @error('recipient_name')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="minimum_amount">Minimum deposit (USDT)</label>
                <input class="portal-input" id="minimum_amount" name="minimum_amount" inputmode="decimal" value="{{ old('minimum_amount', $settings?->minimum_amount ?? '0.00000000') }}" maxlength="21">
                @error('minimum_amount')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="instructions">Payment instructions</label>
                <textarea class="portal-input" id="instructions" name="instructions" rows="6" maxlength="5000" data-instructions-raw="{{ base64_encode((string) old('instructions', $settings?->instructions)) }}">{{ str_replace('BNB Smart Chain', 'BEP20', old('instructions', $settings?->instructions)) }}</textarea>
                @error('instructions')<span class="admin-field-error">{{ $message }}</span>@enderror

                <label for="logo_image">Network logo <span>(PNG, JPG, or WebP; up to 4 MB)</span></label>
                <input class="portal-input" id="logo_image" name="logo_image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                @error('logo_image')<span class="admin-field-error">{{ $message }}</span>@enderror
                @if ($logoImageAvailable)
                    <div class="deposit-admin-qr-preview">
                        <img src="{{ route('admin.deposit-settings.logo') }}" alt="Current BEP20 network logo">
                        <label class="deposit-remove-qr"><input type="checkbox" name="remove_logo" value="1"> Remove current network logo</label>
                    </div>
                @elseif ($settings?->logo_path)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>The saved network logo is missing or outside the managed directory. Upload a replacement.</p></div>
                @endif

                <label for="qr_image">Payment QR image <span>(PNG, JPG, or WebP; up to 4 MB)</span></label>
                <input class="portal-input" id="qr_image" name="qr_image" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                @error('qr_image')<span class="admin-field-error">{{ $message }}</span>@enderror
                @if ($qrImageAvailable)
                    <div class="deposit-admin-qr-preview">
                        <img src="{{ route('admin.deposit-settings.qr') }}" alt="Current payment QR code">
                        <label class="deposit-remove-qr"><input type="checkbox" name="remove_qr" value="1"> Remove current QR image</label>
                    </div>
                @elseif ($settings?->qr_path)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>The saved QR image is missing or outside the managed QR directory. Upload a replacement to restore customer payments.</p></div>
                @endif
                <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked((bool) old('is_enabled', $settings?->is_enabled))> Enable BEP20 deposits</label>
                @error('is_enabled')<span class="admin-field-error">{{ $message }}</span>@enderror
                @if (! $storageReady)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>Deposit settings cannot be saved because the database is missing required columns. Apply pending migrations with <code>php artisan migrate --force</code>, then reload this page.</p></div>
                @endif
                @if (! $contractConfigurationConfigured)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>Deposits cannot be enabled because <code>BEP20_USDT_CONTRACT_ADDRESS</code> is missing from the server environment. Configure it in the server <code>.env</code>, then run <code>php artisan config:clear</code> (or rebuild the cache with <code>php artisan config:cache</code>). Leave deposits disabled until the contract is independently verified.</p></div>
                @elseif (! $contractConfigurationValid)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>The server's <code>BEP20_USDT_CONTRACT_ADDRESS</code> is present but is not a valid EVM address. Correct the configuration before enabling deposits.</p></div>
                @elseif (! $contractMatches)
                    <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>The saved token contract does not match the server-configured contract. Update the saved contract value; casing differences are accepted.</p></div>
                @endif
                <button class="admin-button" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save deposit settings</button>
            </form>
            @if ($settings)
                <form class="admin-delete-settings-form" method="POST" action="{{ route('admin.deposit-settings.destroy') }}" data-confirm="This removes the active payment details and QR image. Existing deposit requests and their audit history will remain unchanged." data-confirm-title="Delete payment settings?" data-confirm-button="Delete settings">
                    @csrf
                    @method('DELETE')
                    <button class="admin-button admin-button-danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> Delete payment settings</button>
                </form>
            @endif
        </section>

        <aside class="admin-panel">
            <div class="admin-panel-heading"><div><span class="admin-eyebrow">CUSTOMER VIEW</span><h2>Configuration status</h2></div></div>
            <dl class="admin-detail-list">
                <div><dt>QR code</dt><dd>{{ $qrImageAvailable ? 'Configured' : ($settings?->qr_path ? 'Unavailable' : 'Not configured') }}</dd></div>
                <div><dt>Network logo</dt><dd>{{ $logoImageAvailable ? 'Configured' : ($settings?->logo_path ? 'Unavailable' : 'Not configured') }}</dd></div>
                <div><dt>Network</dt><dd>{{ $settings?->network_name ?? 'BEP20' }}</dd></div>
                <div><dt>Contract Configuration</dt><dd>{{ $contractConfigurationConfigured ? 'Configured' : 'Missing' }}</dd></div>
                <div><dt>Contract Verification</dt><dd>{{ ! $contractConfigurationConfigured || ! $contractConfigurationValid ? 'Not verifiable' : ($contractMatches ? 'Matched' : 'Mismatch') }}</dd></div>
                <div><dt>Token contract</dt><dd>{{ $settings?->token_contract_address ?: 'Not provided' }}</dd></div>
                <div><dt>Receiving wallet</dt><dd>{{ $settings?->receiving_wallet_address ?: 'Not provided' }}</dd></div>
                <div><dt>Minimum deposit</dt><dd>{{ $settings?->minimum_amount ?? '0.00000000' }} USDT</dd></div>
                <div><dt>Deposit Status</dt><dd>{{ $settings?->is_enabled ? 'Enabled' : 'Disabled' }}</dd></div>
                <div><dt>Recipient</dt><dd>{{ $settings?->recipient_name ?: 'Not provided' }}</dd></div>
                <div><dt>Instructions</dt><dd>{{ filled($settings?->instructions) ? 'Configured' : 'Not provided' }}</dd></div>
                <div><dt>Last updated by</dt><dd>{{ $settings?->updatedBy?->name ?? 'Not updated' }}</dd></div>
                @if ($settings?->updated_at)<div><dt>Last updated</dt><dd>{{ $settings->updated_at->format('M j, Y · H:i') }}</dd></div>@endif
            </dl>
            @unless ($contractConfigurationValid && $contractMatches && $qrImageAvailable && $logoImageAvailable && filled($settings?->receiving_wallet_address) && filled($settings?->recipient_name) && filled($settings?->instructions) && $settings?->minimum_amount > 0 && $settings?->is_enabled)
                <div class="admin-inline-notice"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><p>Deposits are {{ $settings?->is_enabled ? 'marked enabled but configuration is incomplete' : 'disabled' }}. To enable safely, configure and match the trusted server contract, valid receiving wallet, positive minimum, recipient, instructions, logo, and QR image.</p></div>
            @endunless
            <div class="admin-inline-notice"><i class="bi bi-lock" aria-hidden="true"></i><p>Images are stored privately. Never enter or upload wallet private keys or seed phrases. This phase does not verify blockchain transactions or approve deposits automatically.</p></div>
        </aside>
    </div>

    <section class="admin-panel admin-section-gap">
        <div class="admin-panel-heading"><div><span class="admin-eyebrow">AUDIT TRAIL</span><h2>Deposit settings history</h2></div></div>
        <div class="admin-activity-list">
            @forelse ($history as $entry)
                <article class="admin-activity-item"><span class="admin-activity-icon"><i class="bi bi-qr-code" aria-hidden="true"></i></span><div class="admin-activity-copy"><strong>{{ app(\App\Support\AdminAuditPresenter::class)->eventTitle($entry->event) }}</strong><span>{{ $entry->actor?->name ?? 'Administrator' }} · {{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y · H:i') }}</span></div></article>
            @empty<div class="admin-empty-state"><span><i class="bi bi-inbox" aria-hidden="true"></i></span><strong>No settings changes recorded</strong><p>Future updates will be recorded here.</p></div>@endforelse
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        (() => {
            const form = document.getElementById('deposit-settings-form');
            const removeQr = form?.querySelector('[name="remove_qr"]');
            const instructions = form?.querySelector('#instructions');
            if (!form || !removeQr) return;

            form.addEventListener('submit', () => {
                if (removeQr.checked) {
                    form.dataset.confirm = 'Remove the active payment QR? Customer deposit submissions will remain unavailable until a replacement QR is uploaded.';
                    form.dataset.confirmTitle = 'Remove payment QR?';
                    form.dataset.confirmButton = 'Remove QR';
                } else {
                    delete form.dataset.confirm;
                    delete form.dataset.confirmTitle;
                    delete form.dataset.confirmButton;
                }
            });

            if (instructions) {
                const displayedInstructions = instructions.value;
                form.addEventListener('submit', () => {
                    if (instructions.value === displayedInstructions && (!form.dataset.confirm || form.dataset.confirmed === 'true')) {
                        const rawBytes = Uint8Array.from(atob(instructions.dataset.instructionsRaw), (character) => character.charCodeAt(0));
                        instructions.value = new TextDecoder().decode(rawBytes);
                    }
                });
            }
        })();
    </script>
@endsection
