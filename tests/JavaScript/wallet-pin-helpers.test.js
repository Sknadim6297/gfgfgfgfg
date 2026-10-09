import assert from 'node:assert/strict';
import test from 'node:test';
import { createRequestGuard, releaseFocusWithin, restoreFocus } from '../../resources/js/wallet-pin-helpers.js';

test('request guard rejects a duplicate request until the first request finishes', async () => {
    const guard = createRequestGuard();
    let finishRequest;
    const request = new Promise((resolve) => { finishRequest = resolve; });

    assert.equal(guard.begin(), true);
    assert.equal(guard.pending, true);
    assert.equal(guard.begin(), false);

    finishRequest();
    guard.end();
    assert.equal(guard.pending, false);
    assert.equal(guard.begin(), true);
});

test('focus is released only when it is inside a container about to be hidden', () => {
    let blurCount = 0;
    const activeElement = { blur: () => { blurCount++; } };
    const modal = { contains: (element) => element === activeElement };
    const unrelatedContainer = { contains: () => false };

    assert.equal(releaseFocusWithin(activeElement, modal), true);
    assert.equal(blurCount, 1);
    assert.equal(releaseFocusWithin(activeElement, unrelatedContainer), false);
    assert.equal(blurCount, 1);
    assert.equal(releaseFocusWithin(null, modal), false);
});

test('focus restoration skips disconnected or hidden modal openers', () => {
    let focusCount = 0;
    const availableTarget = {
        isConnected: true,
        closest: () => null,
        focus: () => { focusCount++; },
    };
    const hiddenTarget = {
        ...availableTarget,
        closest: (selector) => selector === '[aria-hidden="true"]' ? {} : null,
    };

    assert.equal(restoreFocus(availableTarget), true);
    assert.equal(restoreFocus(hiddenTarget), false);
    assert.equal(restoreFocus({ ...availableTarget, isConnected: false }), false);
    assert.equal(focusCount, 1);
});
