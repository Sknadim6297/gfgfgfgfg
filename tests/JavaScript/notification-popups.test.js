import assert from 'node:assert/strict';
import test from 'node:test';
import { showConfirmation, showNotification } from '../../resources/js/notification-popups.js';

test('success notifications use the shared SweetAlert2 popup style', () => {
    const calls = [];
    const swal = { fire: (options) => calls.push(options) };

    showNotification(swal, 'Wallet Transaction PIN set successfully.', 'success');

    assert.deepEqual(calls, [{
        icon: 'success',
        title: 'Success',
        text: 'Wallet Transaction PIN set successfully.',
        confirmButtonText: 'OK',
        customClass: { htmlContainer: 'quickpay-alert-text' },
    }]);
});

test('error notifications use the shared SweetAlert2 error popup style', () => {
    const calls = [];
    const swal = { fire: (options) => calls.push(options) };

    showNotification(swal, 'Invalid verification code.', 'error');

    assert.deepEqual(calls, [{
        title: 'Unable to complete request',
        text: 'Invalid verification code.',
        icon: 'error',
        confirmButtonText: 'OK',
        customClass: { htmlContainer: 'quickpay-alert-text' },
    }]);
});

test('warning notifications use the shared SweetAlert2 warning popup style', () => {
    const calls = [];
    const swal = { fire: (options) => calls.push(options) };

    showNotification(swal, 'The exchange rate changed.', 'warning');

    assert.deepEqual(calls, [{
        title: 'Please note',
        text: 'The exchange rate changed.',
        icon: 'warning',
        confirmButtonText: 'OK',
        customClass: { htmlContainer: 'quickpay-alert-text' },
    }]);
});

test('validation notifications use an accessible error popup title', () => {
    const calls = [];
    const swal = { fire: (options) => calls.push(options) };

    showNotification(swal, 'Enter a valid amount.', 'error', 'Please check the form');

    assert.equal(calls[0].title, 'Please check the form');
    assert.equal(calls[0].text, 'Enter a valid amount.');
    assert.equal(calls[0].icon, 'error');
});

test('confirmations use a cancelable SweetAlert2 warning dialog', () => {
    const calls = [];
    const swal = { fire: (options) => calls.push(options) };

    showConfirmation(swal, { title: 'Review this deposit?', text: 'Verify payment first.', confirmButtonText: 'Save review' });

    assert.deepEqual(calls, [{
        title: 'Review this deposit?',
        text: 'Verify payment first.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Save review',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true,
    }]);
});
