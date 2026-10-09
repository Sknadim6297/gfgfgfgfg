import Swal from 'sweetalert2';
import { showNotification } from './notification-popups';
import { createRequestGuard, releaseFocusWithin, restoreFocus } from './wallet-pin-helpers';

document.querySelectorAll('[data-wallet-pin-modal]').forEach((modal) => {
    const required = (selector) => {
        const element = modal.querySelector(selector);
        if (!element) {
            throw new Error(`Wallet PIN modal is missing required element: ${selector}`);
        }

        return element;
    };
    const instance = window.bootstrap?.Modal.getOrCreateInstance(modal);
    if (!instance) {
        throw new Error('Wallet PIN modal requires Bootstrap Modal to be loaded before app.js.');
    }

    const purpose = required('[data-pin-purpose]');
    const title = required('[data-pin-title]');
    const codeInput = required('[data-pin-code]');
    const otpDigits = [...modal.querySelectorAll('[data-pin-otp-digit]')];
    const pinGroups = [...document.querySelectorAll('[data-pin-input-group]')];
    const testingOtp = modal.querySelector('[data-pin-testing-otp]');
    const testingOtpValue = modal.querySelector('[data-pin-testing-otp-value]');
    if (Boolean(testingOtp) !== Boolean(testingOtpValue)) {
        throw new Error('Wallet PIN modal must include both testing OTP elements or neither.');
    }
    const sendButtons = [...modal.querySelectorAll('[data-pin-send], [data-pin-resend]')];
    const resendButton = required('[data-pin-resend]');
    const countdown = required('[data-pin-countdown]');
    let pendingPurpose = modal.dataset.pendingPurpose || null;
    let verifiedPurpose = modal.dataset.verifiedPurpose || null;
    const requestGuard = createRequestGuard();
    let modalOpener = null;
    let rateLimitUntil = 0;
    let resendCooldownUntil = 0;
    let countdownTimer = null;
    const steps = [...modal.querySelectorAll('[data-pin-step]')];
    const stepNames = steps.map((step) => step.dataset.pinStep);
    for (const stepName of ['send', 'verify', 'save']) {
        if (stepNames.filter((name) => name === stepName).length !== 1) {
            throw new Error(`Wallet PIN modal must contain exactly one "${stepName}" step.`);
        }
    }
    if (steps.length !== 3) {
        throw new Error(`Wallet PIN modal requires exactly 3 steps; found ${steps.length}.`);
    }
    if (otpDigits.length !== 6) {
        throw new Error(`Wallet PIN modal requires 6 OTP digit fields; found ${otpDigits.length}.`);
    }
    required('[data-pin-send]');
    required('[data-pin-verify]');
    required('[data-pin-save]');
    required('[data-pin-new]');
    required('[data-pin-confirm]');
    required('[data-pin-csrf]');
    for (const attribute of ['sendUrl', 'verifyUrl', 'saveUrl']) {
        if (!modal.dataset[attribute]) {
            throw new Error(`Wallet PIN modal is missing its ${attribute} endpoint.`);
        }
    }
    const modalPinGroups = [...modal.querySelectorAll('[data-pin-input-group]')];
    if (modalPinGroups.length !== 2 || modalPinGroups.some((group) => group.querySelectorAll('[data-pin-digit]').length !== 4)) {
        throw new Error('Wallet PIN modal requires two four-digit PIN input groups.');
    }
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
            button.disabled = requestGuard.pending || rateLimited || coolingDown;
        });
        required('[data-pin-verify]').disabled = requestGuard.pending;
        required('[data-pin-save]').disabled = requestGuard.pending;

        if (rateLimitSeconds > 0) {
            countdown.hidden = false;
            countdown.textContent = `Try again in ${formatTime(rateLimitSeconds)}`;
        } else if (cooldownSeconds > 0) {
            countdown.hidden = false;
            countdown.textContent = `Resend code in ${formatTime(cooldownSeconds)}`;
        } else {
            countdown.hidden = true;
            countdown.textContent = '';
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
    const notify = async (message, type, returnFocus = null) => {
        releaseFocusWithin(document.activeElement, modal, modal.closest('.main-wrapper'));
        await showNotification(Swal, message, type);

        const target = modal.classList.contains('show') ? returnFocus : modalOpener;
        window.setTimeout(() => restoreFocus(target), 0);
    };
    const hideModal = () => new Promise((resolve) => {
        if (!modal.classList.contains('show')) {
            resolve();
            return;
        }
        modal.addEventListener('hidden.bs.modal', resolve, { once: true });
        instance.hide();
    });
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
        const isVerified = verifiedPurpose === value;
        showStep(isVerified ? 'save' : pendingPurpose === value ? 'verify' : 'send');
        title.textContent = isVerified ? 'Create Wallet Transaction PIN' : 'Wallet Transaction PIN';
        clearOtp();
        if (testingOtp && testingOtpValue) {
            testingOtp.hidden = true;
            testingOtpValue.textContent = '';
        }
        required('[data-pin-new]').value = '';
        required('[data-pin-confirm]').value = '';
        pinGroups.forEach((group) => {
            pinGroupDigits(group).forEach((input) => { input.value = ''; });
            updatePinValue(group);
        });
        updateCountdown();
    };
    const post = async (url, values) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': required('[data-pin-csrf]').value,
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
                required('[data-pin-verify]').click();
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
            if (requestGuard.pending || rateLimitUntil > Date.now() || (button === resendButton && resendCooldownUntil > Date.now())) return;
            requestGuard.begin();
            updateCountdown();
            try {
                const result = await post(modal.dataset.sendUrl, { purpose: purpose.value });
                if (verifiedPurpose === purpose.value) verifiedPurpose = null;
                pendingPurpose = purpose.value;
                releaseFocusWithin(document.activeElement, modal);
                showStep('verify');
                clearOtp();
                if (result.testing_otp && (!testingOtp || !testingOtpValue)) {
                    throw new Error('Wallet PIN modal is missing the testing OTP display.');
                }
                if (testingOtp && testingOtpValue) {
                    testingOtp.hidden = !result.testing_otp;
                    testingOtpValue.textContent = result.testing_otp ?? '';
                }
                startResendCooldown();
                await notify(result.message, 'success', otpDigits[0]);
            } catch (exception) {
                await notify(exception.message, 'error', button);
                if (exception.status === 429 && Number.isFinite(exception.retryAfter) && exception.retryAfter > 0) {
                    startRateLimit(exception.retryAfter);
                }
            } finally {
                requestGuard.end();
                updateCountdown();
            }
        });
    });
    required('[data-pin-verify]').addEventListener('click', async (event) => {
        if (!requestGuard.begin()) return;
        updateCountdown();
        try {
            const result = await post(modal.dataset.verifyUrl, {
                purpose: purpose.value,
                code: otpValue(),
            });
            pendingPurpose = null;
            verifiedPurpose = purpose.value;
            releaseFocusWithin(document.activeElement, modal);
            showStep('save');
            title.textContent = 'Create Wallet Transaction PIN';
            await notify(result.message, 'success', required('[data-pin-new]'));
        } catch (exception) {
            let target = otpDigits[0];
            if (/^This verification code has expired|^This verification code has already been used/.test(exception.message)) {
                releaseFocusWithin(document.activeElement, modal);
                pendingPurpose = null;
                setPurpose(purpose.value);
                target = required('[data-pin-send]');
            }
            await notify(exception.message, 'error', target);
        } finally {
            requestGuard.end();
            updateCountdown();
        }
    });
    required('[data-pin-save]').addEventListener('click', async (event) => {
        if (requestGuard.pending) return;
        try {
            const pin = required('[data-pin-new]').value;
            const confirmation = required('[data-pin-confirm]').value;
            if (!/^\d{4}$/.test(pin) || !/^\d{4}$/.test(confirmation)) {
                await notify('Enter and confirm a matching four-digit Wallet Transaction PIN.', 'error', required('[data-pin-new]'));
                return;
            }
            requestGuard.begin();
            updateCountdown();
            const result = await post(modal.dataset.saveUrl, {
                purpose: purpose.value,
                wallet_transaction_pin: pin,
                wallet_transaction_pin_confirmation: confirmation,
            });
            verifiedPurpose = null;
            pendingPurpose = null;
            const isConfigured = true;
            document.querySelectorAll('[data-wallet-pin-entry]').forEach((field) => { field.hidden = false; });
            document.querySelectorAll('[data-pin-required-submit]').forEach((button) => { button.disabled = false; });
            document.querySelectorAll('[data-pin-status]').forEach((status) => { status.textContent = 'Active'; });
            modal.dispatchEvent(new CustomEvent('wallet-pin:saved', { bubbles: true, detail: { isConfigured } }));
            await hideModal();
            await notify(result.message, 'success', modalOpener);
        } catch (exception) {
            required('[data-pin-new]').value = '';
            required('[data-pin-confirm]').value = '';
            let target = required('[data-pin-save]');
            if (/^(This verification code has expired|This verification code has already been used|Verify a current email code)/.test(exception.message)) {
                releaseFocusWithin(document.activeElement, modal);
                verifiedPurpose = null;
                pendingPurpose = null;
                setPurpose(purpose.value);
                target = required('[data-pin-send]');
            }
            await notify(exception.message, 'error', target);
        } finally {
            requestGuard.end();
            updateCountdown();
        }
    });
    modal.querySelector('[data-pin-reset]')?.addEventListener('click', () => setPurpose('wallet_password_reset'));
    modal.addEventListener('hide.bs.modal', () => {
        releaseFocusWithin(document.activeElement, modal);
    });
    modal.addEventListener('hidden.bs.modal', () => {
        setPurpose(purpose.value);
        restoreFocus(modalOpener);
    });
    document.querySelectorAll('[data-wallet-pin-open]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            setPurpose(verifiedPurpose ?? pendingPurpose ?? button.dataset.walletPinPurpose ?? purpose.value);
            modalOpener = button;
            instance.show();
        });
    });
    if (modal.hasAttribute('data-auto-open')) {
        setPurpose('wallet_password_set');
        modalOpener = document.querySelector('[data-wallet-pin-open]');
        instance.show();
    }
});
