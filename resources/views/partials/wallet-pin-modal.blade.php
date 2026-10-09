<div class="modal fade wallet-pin-modal" id="walletTransactionPinModal" tabindex="-1" aria-labelledby="walletTransactionPinModalTitle" aria-hidden="true" data-wallet-pin-modal data-send-url="{{ route('wallet-pin.otp.send') }}" data-verify-url="{{ route('wallet-pin.otp.verify') }}" data-save-url="{{ route('wallet-pin.save') }}" @if (($autoOpenWalletPin ?? false) && ! $user->hasWalletTransactionPin()) data-auto-open @endif>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="admin-eyebrow">ACCOUNT SECURITY</span><h2 class="modal-title" id="walletTransactionPinModalTitle" data-pin-title>Wallet Transaction PIN</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="wallet-pin-modal-copy">Verify your registered email <strong>{{ $maskedEmail }}</strong> to set or update the PIN used for Sell and withdrawal requests.</p>
                <p class="wallet-pin-countdown" data-pin-countdown role="status" hidden></p>
                <input type="hidden" value="{{ csrf_token() }}" data-pin-csrf>
                <input type="hidden" value="{{ $user->hasWalletTransactionPin() ? 'wallet_password_change' : 'wallet_password_set' }}" data-pin-purpose>
                <section data-pin-step="send">
                    <button class="profile-save-button" type="button" data-pin-send>Send OTP</button>
                    @if ($user->hasWalletTransactionPin())
                        <button class="profile-clear-filter wallet-pin-reset-link" type="button" data-pin-reset>Forgot / Reset Transaction PIN</button>
                    @endif
                </section>
                <section data-pin-step="verify" hidden>
                    <p class="wallet-pin-delivery" data-pin-delivery>Verification code sent to<br><strong>{{ $maskedEmail }}</strong></p>
                    <p class="wallet-pin-testing-otp" data-pin-testing-otp hidden>Testing OTP: <strong data-pin-testing-otp-value></strong></p>
                    <label id="wallet-pin-otp-label">Email OTP</label>
                    <div class="wallet-pin-otp-boxes" role="group" aria-labelledby="wallet-pin-otp-label" data-pin-otp-boxes>
                        @for ($digit = 1; $digit <= 6; $digit++)
                            <input class="profile-form-control wallet-pin-otp-digit" id="wallet-pin-otp-digit-{{ $digit }}" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="{{ $digit === 1 ? 'one-time-code' : 'off' }}" aria-label="OTP digit {{ $digit }} of 6" data-pin-otp-digit>
                        @endfor
                    </div>
                    <input type="hidden" data-pin-code>
                    <button class="profile-save-button wallet-pin-primary-action" type="button" data-pin-verify>Verify OTP</button>
                    <p class="wallet-pin-resend">Didn't receive the code? <button class="wallet-pin-reset-link" type="button" data-pin-resend>Resend OTP</button></p>
                </section>
                <section data-pin-step="save" hidden>
                    <div class="wallet-pin-field">
                        <label id="wallet-pin-new-label">New Wallet Transaction PIN</label>
                        <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="wallet-pin-new-label" data-pin-input-group>
                            @for ($digit = 1; $digit <= 4; $digit++)
                                <input class="profile-form-control wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="New PIN digit {{ $digit }} of 4" data-pin-digit>
                            @endfor
                        </div>
                        <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                        <input type="hidden" data-pin-value data-pin-new>
                    </div>
                    <div class="wallet-pin-field">
                        <label id="wallet-pin-confirm-label">Confirm PIN</label>
                        <div class="wallet-transaction-pin-boxes" role="group" aria-labelledby="wallet-pin-confirm-label" data-pin-input-group>
                            @for ($digit = 1; $digit <= 4; $digit++)
                                <input class="profile-form-control wallet-transaction-pin-digit" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" aria-label="Confirmation PIN digit {{ $digit }} of 4" data-pin-digit>
                            @endfor
                        </div>
                        <span class="wallet-pin-field-error" data-pin-input-error hidden></span>
                        <input type="hidden" data-pin-value data-pin-confirm>
                    </div>
                    <button class="profile-save-button wallet-pin-primary-action" type="button" data-pin-save>Set Wallet Transaction PIN</button>
                </section>
            </div>
        </div>
    </div>
</div>
