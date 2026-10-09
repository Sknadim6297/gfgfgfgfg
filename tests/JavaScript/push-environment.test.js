import assert from 'node:assert/strict';
import test from 'node:test';
import {
    getNotificationPermissionMessage,
    getPushEnvironmentMessage,
    inspectPushEnvironment,
} from '../../resources/js/push-environment.js';

function environment({
    userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0.0.0',
    platform = 'Linux x86_64',
    standalone = false,
    displayMode = false,
    maxTouchPoints = 0,
    notification = true,
    serviceWorker = true,
    secure = true,
} = {}) {
    const windowObject = {
        isSecureContext: secure,
        Notification: notification ? { requestPermission() {} } : undefined,
        matchMedia: () => ({ matches: displayMode }),
    };
    const navigatorObject = {
        userAgent,
        platform,
        standalone,
        maxTouchPoints,
        serviceWorker: serviceWorker ? { register() {} } : undefined,
    };

    return inspectPushEnvironment(windowObject, navigatorObject, userAgent);
}

test('requires iOS users to install the app before enabling web push in Safari', () => {
    const result = environment({
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1',
        platform: 'iPhone',
    });

    assert.equal(result.isIos, true);
    assert.equal(result.isStandalone, false);
    assert.match(getPushEnvironmentMessage(result).message, /Add to Home Screen/);
    assert.match(getPushEnvironmentMessage(result).message, /iOS 16\.4 or newer/);
});

test('allows supported APIs in an installed iOS Home Screen app', () => {
    const result = environment({
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile',
        platform: 'iPhone',
        standalone: true,
    });
    assert.equal(result.isIos, true);
    assert.equal(result.isStandalone, true);
    assert.equal(getPushEnvironmentMessage(result), null);
});

test('allows notification-capable Android browsers with secure context and service workers', () => {
    const result = environment({
        userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36',
        platform: 'Linux armv8l',
    });

    assert.equal(result.isIos, false);
    assert.equal(result.hasNotificationApi, true);
    assert.equal(result.hasServiceWorkerApi, true);
    assert.equal(result.isSecureContext, true);
    assert.equal(getPushEnvironmentMessage(result), null);
});

test('detects iPadOS desktop user agents from the touch-enabled Mac platform', () => {
    const result = environment({
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Safari/605.1.15',
        platform: 'MacIntel',
        maxTouchPoints: 5,
    });

    assert.equal(result.isIos, true);
    assert.match(getPushEnvironmentMessage(result).message, /Add to Home Screen/);
});

test('reports unsupported browser APIs without attempting a Firebase subscription', () => {
    const result = environment({ notification: false, serviceWorker: false });

    assert.match(getPushEnvironmentMessage(result).message, /does not support web push/);
});

test('requires HTTPS before enabling web push', () => {
    const result = environment({ secure: false });

    assert.equal(getPushEnvironmentMessage(result).code, 'https-required');
});

test('recognizes installed mode from the display-mode media query', () => {
    const result = environment({
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile',
        platform: 'iPhone',
        displayMode: true,
    });

    assert.equal(result.isStandalone, true);
    assert.equal(getPushEnvironmentMessage(result), null);
});

test('explains denied notification permission without exposing browser data', () => {
    assert.match(getNotificationPermissionMessage('denied'), /browser or device settings/);
    assert.equal(getNotificationPermissionMessage('granted'), null);
});
