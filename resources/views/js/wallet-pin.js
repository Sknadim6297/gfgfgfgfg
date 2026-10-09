document.querySelectorAll('[data-wallet-pin-modal]').forEach((modal) => {
    const instance = window.bootstrap?.Modal.getOrCreateInstance(modal);
    if (!instance) return;

    const purpose = modal.querySelector('[data-pin-purpose]');
    const message = modal.querySelector('[data-pin-message]');
    const error = modal.querySelector('[data-pin-error]');
    const title = modal.querySelector('[data-pin-title]');
    const codeInput = modal.querySelector('[data-pin-code]');
    const otpDigits = [...modal.querySelectorAll('[data-pin-otp-digit]')];
    const pinGroups = [...document.querySelectorAll('[data-pin-input-group]')];
    const testingOtp = modal.querySelector('[data-pin-testing-otp]');
    const testingOtpValue = modal.querySelector('[data-pin-testing-otp-value]');
    const sendButtons = [...modal.querySelectorAll('[data-pin-send], [data-pin-resend]')];
    const resendButton = modal.querySelector('[data-pin-resend]');
    const countdown = modal.querySelector('[data-pin-countdown]');
    let requestPending = false;
    let rateLimitUntil = 0;
    let resendCooldownUntil = 0;
    let countdownTimer = null;
    const steps = [...modal.querySelectorAll('[data-pin-step]')];
    const showStep = (name) => steps.forEach((step) => { step.hidden = step.dataset.pinStep !== name; });
    const otpValue = () => otpDigits.map((input) => input.value).join('');
    const syncOtpValue = () => { codeInput.value = otpValue(); };
    const clearOtp = () => {
        otpDigits.forEach((input) => { input.value = ''; });
        syncOtpValue();
    };
    const fillOtp = (value) => {
        const digits = value.replace(/\D/g, '').slice(0, otpDigits.length);
        otpDigits.forEach((input, index) => { input.value = digits[index] ?? ''; });
        syncOtpValue();
        otpDigits[Math.min(digits.length, otpDigits.length - 1)]?.focus();
    };
    const showMessage = (text, failed = false) => {
        message.hidden = failed || !text;
        error.hidden = !failed || !text;
        message.textContent = failed ? '' : text;
        error.textContent = failed ? text : '';
    };
    const formatTime = (seconds) => {
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    };
    const updateCountdown = () => {
        const now = Date.now();
        const rateLimitSeconds = Math.max(0, Math.ceil((rateLimitUntil - now) / 1000));
        const cooldownSeconds = Math.max(0, Math.ceil((resendCooldownUntil - now) / 1000));
        sendButtons.forEach((button) => {
            const rateLimited = rateLimitSeconds > 0;
            const coolingDown = button === resendButton && cooldownSeconds > 0;
            button.disabled = requestPending || rateLimited || coolingDown;
        });

        if (rateLimitSeconds > 0) {
            countdown.hidden = false;
            countdown.textContent = `Try again in ${formatTime(rateLimitSeconds)}`;
            showMessage(`Too many OTP requests.\nPlease try again in ${formatTime(rateLimitSeconds)}.`, true);
        } else if (cooldownSeconds > 0) {
            countdown.hidden = false;
            countdown.textContent = `Resend code in ${formatTime(cooldownSeconds)}`;
            if (error.textContent.startsWith('Too many OTP requests.')) showMessage('');
        } else {
            countdown.hidden = true;
            countdown.textContent = '';
            if (error.textContent.startsWith('Too many OTP requests.')) showMessage('');
        }

        if (rateLimitSeconds === 0) rateLimitUntil = 0;
        if (cooldownSeconds === 0) resendCooldownUntil = 0;
        if (rateLimitUntil === 0 && resendCooldownUntil === 0 && countdownTimer !== null) {
            window.clearInterval(countdownTimer);
            countdownTimer = null;
        }
    };
    const startCountdown = () => {
        updateCountdown();
        if (countdownTimer === null) countdownTimer = window.setInterval(updateCountdown, 1000);
    };
    const startRateLimit = (retryAfter) => {
        rateLimitUntil = Date.now() + retryAfter * 1000;
        resendCooldownUntil = 0;
        startCountdown();
    };
    const startResendCooldown = () => {
        resendCooldownUntil = Date.now() + 60_000;
        startCountdown();
    };
    const pinGroupDigits = (group) => [...group.querySelectorAll('[data-pin-digit]')];
    const updatePinValue = (group) => {
        const value = pinGroupDigits(group).map((input) => input.value).join('');
        const hidden = group.parentElement.querySelector('[data-pin-value]');
        if (hidden) hidden.value = value;
        const errorMessage = group.parentElement.querySelector('[data-pin-input-error]');
        if (errorMessage && value.length === 4) {
            errorMessage.hidden = true;
            errorMessage.textContent = '';
        }
    };
    const fillPinGroup = (group, value) => {
        if (!/^\d{4}$/.test(value)) return;
        const digits = pinGroupDigits(group);
        digits.forEach((input, index) => { input.value = value[index]; });
        updatePinValue(group);
        digits[digits.length - 1]?.focus();
    };
    const setPurpose = (value) => {
        purpose.value = value;
        showStep('send');
        title.textContent = 'Wallet Transaction PIN';
        showMessage('');
        clearOtp();
        testingOtp.hidden = true;
        testingOtpValue.textContent = '';
        modal.querySelector('[data-pin-new]').value = '';
        modal.querySelector('[data-pin-confirm]').value = '';
        pinGroups.forEach((group) => {
            pinGroupDigits(group).forEach((input) => { input.value = ''; });
            updatePinValue(group);
        });
        updateCountdown();
    };
    const post = async (url, values) => {
        showMessage('');
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': modal.querySelector('[data-pin-csrf]').value,
            },
            body: new URLSearchParams(values),
        });
        const result = await response.json();
        if (!response.ok) {
            const firstError = Object.values(result.errors ?? {}).flat()[0];
            if (response.status === 429) {
                const limitError = new Error(result.message ?? 'Too many OTP requests.');
                limitError.status = response.status;
                limitError.retryAfter = Number(result.retry_after);
                throw limitError;
            }
            const requestError = new Error(firstError ?? result.message ?? 'Unable to complete this security step.');
            requestError.status = response.status;
            throw requestError;
        }
        return result;
    };

    otpDigits.forEach((input, index) => {
        input.addEventListener('input', () => {
            const digits = input.value.replace(/\D/g, '');
            if (digits.length > 1) {
                fillOtp(digits);
                return;
            }
            input.value = digits.slice(0, 1);
            syncOtpValue();
            if (input.value && index < otpDigits.length - 1) otpDigits[index + 1].focus();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !input.value && index > 0) {
                otpDigits[index - 1].value = '';
                syncOtpValue();
                otpDigits[index - 1].focus();
            } else if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                otpDigits[index - 1].focus();
            } else if (event.key === 'ArrowRight' && index < otpDigits.length - 1) {
                event.preventDefault();
                otpDigits[index + 1].focus();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                modal.querySelector('[data-pin-verify]').click();
            }
        });
        input.addEventListener('paste', (event) => {
            const pasted = event.clipboardData?.getData('text') ?? '';
            if (pasted.replace(/\D/g, '').length === otpDigits.length) {
                event.preventDefault();
                fillOtp(pasted);
            }
        });
    });

    pinGroups.forEach((group) => {
        const digits = pinGroupDigits(group);
        digits.forEach((input, index) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/\D/g, '').slice(0, 1);
                updatePinValue(group);
                if (input.value && index < digits.length - 1) digits[index + 1].focus();
            });
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Backspace' && !input.value && index > 0) {
                    digits[index - 1].value = '';
                    updatePinValue(group);
                    digits[index - 1].focus();
                } else if (event.key === 'ArrowLeft' && index > 0) {
                    event.preventDefault();
                    digits[index - 1].focus();
                } else if (event.key === 'ArrowRight' && index < digits.length - 1) {
                    event.preventDefault();
                    digits[index + 1].focus();
                }
            });
            input.addEventListener('paste', (event) => {
                const pasted = event.clipboardData?.getData('text') ?? '';
                if (/^\d{4}$/.test(pasted)) {
                    event.preventDefault();
                    fillPinGroup(group, pasted);
                } else if (pasted.length > 1) {
                    event.preventDefault();
                }
            });
        });

        group.closest('form')?.addEventListener('submit', (event) => {
            updatePinValue(group);
            const hidden = group.parentElement.querySelector('[data-pin-value]');
            if (hidden?.hasAttribute('data-pin-required-input') && !/^\d{4}$/.test(hidden.value)) {
                event.preventDefault();
                const errorMessage = group.parentElement.querySelector('[data-pin-input-error]');
                if (errorMessage) {
                    errorMessage.textContent = 'Enter your four-digit Wallet Transaction PIN.';
                    errorMessage.hidden = false;
                }
                digits.find((input) => !input.value)?.focus();
            }
        });
    });

    sendButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (requestPending || rateLimitUntil > Date.now() || (button === resendButton && resendCooldownUntil > Date.now())) return;
            requestPending = true;
            updateCountdown();
            try {
                const result = await post(modal.dataset.sendUrl, { purpose: purpose.value });
                showStep('verify');
                showMessage('');
                clearOtp();
                testingOtp.hidden = !result.testing_otp;
                testingOtpValue.textContent = result.testing_otp ?? '';
                startResendCooldown();
                otpDigits[0].focus();
            } catch (exception) {
                showMessage(exception.message, true);
                if (exception.status === 429 && Number.isFinite(exception.retryAfter) && exception.retryAfter > 0) {
                    startRateLimit(exception.retryAfter);
                }
            } finally {
                requestPending = false;
                updateCountdown();
            }
        });
    });
    modal.querySelector('[data-pin-verify]').addEventListener('click', async () => {
        try {
            const result = await post(modal.dataset.verifyUrl, {
                purpose: purpose.value,
                code: otpValue(),
            });
            showStep('save');
            title.textContent = 'Create Wallet Transaction PIN';
            showMessage(result.message);
            modal.querySelector('[data-pin-new]').focus();
        } catch (exception) {
            showMessage(exception.message, true);
        }
    });
    modal.querySelector('[data-pin-save]').addEventListener('click', async () => {
        try {
            const pin = modal.querySelector('[data-pin-new]').value;
            const confirmation = modal.querySelector('[data-pin-confirm]').value;
            if (!/^\d{4}$/.test(pin) || !/^\d{4}$/.test(confirmation)) {
                showMessage('Enter and confirm a matching four-digit Wallet Transaction PIN.', true);
                return;
            }
            const result = await post(modal.dataset.saveUrl, {
                purpose: purpose.value,
                wallet_transaction_pin: pin,
                wallet_transaction_pin_confirmation: confirmation,
            });
            const isConfigured = true;
            document.querySelectorAll('[data-wallet-pin-entry]').forEach((field) => { field.hidden = false; });
            document.querySelectorAll('[data-pin-required-submit]').forEach((button) => { button.disabled = false; });
            document.querySelectorAll('[data-pin-status]').forEach((status) => { status.textContent = 'Active'; });
            showMessage(result.message);
            document.querySelectorAll('[data-pin-success-message]').forEach((notice) => {
                notice.textContent = result.message;
                notice.hidden = false;
            });
            modal.dispatchEvent(new CustomEvent('wallet-pin:saved', { bubbles: true, detail: { isConfigured } }));
            instance.hide();
            document.querySelector('[data-pin-required-input]')?.parentElement.querySelector('[data-pin-digit]')?.focus();
        } catch (exception) {
            modal.querySelector('[data-pin-new]').value = '';
            modal.querySelector('[data-pin-confirm]').value = '';
            showMessage(exception.message, true);
        }
    });
    modal.querySelector('[data-pin-reset]')?.addEventListener('click', () => setPurpose('wallet_password_reset'));
    modal.addEventListener('hidden.bs.modal', () => setPurpose(
        modal.querySelector('[data-wallet-pin-open]')?.dataset.walletPinPurpose ?? purpose.value,
    ));
    document.querySelectorAll('[data-wallet-pin-open]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            setPurpose(button.dataset.walletPinPurpose ?? purpose.value);
            instance.show();
        });
    });
    if (modal.hasAttribute('data-auto-open')) {
        setPurpose('wallet_password_set');
        instance.show();
    }
});
