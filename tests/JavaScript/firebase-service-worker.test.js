import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const workerSource = await readFile(new URL('../../public/firebase-messaging/sw.js', import.meta.url), 'utf8');

function createWorker({ clients = [] } = {}) {
    const handlers = new Map();
    const notifications = [];
    const openedWindows = [];
    const self = {
        location: { origin: 'https://quickpay.example.test' },
        registration: {
            showNotification: async (title, options) => notifications.push({ title, options }),
        },
        clients: {
            matchAll: async () => clients,
            openWindow: async (url) => openedWindows.push(url),
        },
        addEventListener: (type, callback) => {
            const callbacks = handlers.get(type) ?? [];
            callbacks.push(callback);
            handlers.set(type, callbacks);
        },
    };

    vm.runInNewContext(workerSource, { self, URL, Promise });

    return { handlers, notifications, openedWindows };
}

test('background push displays one notification with an absolute same-origin logo and click URL', async () => {
    const worker = createWorker();
    const event = {
        data: {
            json: () => ({
                data: {
                    url: 'https://quickpay.example.test/profile',
                },
                notification: {
                    title: 'Campaign title from admin',
                    body: 'Campaign message from admin',
                    icon: 'https://quickpay.example.test/icons/icon-192.png',
                    tag: 'quickpay-push-17',
                },
            }),
        },
        waitUntil: (promise) => { event.promise = promise; },
    };

    assert.equal(worker.handlers.get('push').length, 1);
    worker.handlers.get('push')[0](event);
    await event.promise;

    assert.equal(worker.notifications.length, 1);
    assert.equal(worker.notifications[0].title, 'Campaign title from admin');
    assert.equal(worker.notifications[0].options.body, 'Campaign message from admin');
    assert.equal(worker.notifications[0].options.icon, 'https://quickpay.example.test/icons/icon-192.png');
    assert.equal(worker.notifications[0].options.tag, 'quickpay-push-17');
    assert.equal(worker.notifications[0].options.data.url, '/profile');
});

test('notification click closes and focuses an existing matching website tab', async () => {
    const calls = [];
    const existingClient = {
        url: 'https://quickpay.example.test/home',
        navigate: async (url) => calls.push(['navigate', url]),
        focus: async () => calls.push(['focus']),
    };
    const worker = createWorker({ clients: [existingClient] });
    let closed = false;
    let waited;
    const event = {
        notification: {
            close: () => { closed = true; },
            data: { url: '/profile?tab=notifications' },
        },
        waitUntil: (promise) => { waited = promise; },
    };

    assert.equal(worker.handlers.get('notificationclick').length, 1);
    worker.handlers.get('notificationclick')[0](event);
    await waited;

    assert.equal(closed, true);
    assert.deepEqual(calls, [
        ['navigate', 'https://quickpay.example.test/profile?tab=notifications'],
        ['focus'],
    ]);
    assert.deepEqual(worker.openedWindows, []);
});

test('notification click opens the same-origin destination when no website tab exists', async () => {
    const worker = createWorker();
    let waited;
    const event = {
        notification: {
            close() {},
            data: { url: '/profile' },
        },
        waitUntil: (promise) => { waited = promise; },
    };

    worker.handlers.get('notificationclick')[0](event);
    await waited;

    assert.deepEqual(worker.openedWindows, ['https://quickpay.example.test/profile']);
});

test('notification click rejects cross-origin destinations and uses the profile default', async () => {
    const worker = createWorker();
    let waited;
    const event = {
        notification: {
            close() {},
            data: { url: 'https://attacker.example/phishing' },
        },
        waitUntil: (promise) => { waited = promise; },
    };

    worker.handlers.get('notificationclick')[0](event);
    await waited;

    assert.deepEqual(worker.openedWindows, ['https://quickpay.example.test/profile']);
});
