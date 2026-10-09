import { getApp, getApps, initializeApp } from '@firebase/app';
import { deleteToken, getMessaging, getToken, isSupported, onMessage } from '@firebase/messaging';
import { getNotificationPermissionMessage, getPushEnvironmentMessage, inspectPushEnvironment } from './push-environment';

class UserFacingError extends Error {
    constructor(message, code) {
        super(message);
        this.code = code;
    }
}

const controls = document.querySelector('[data-fcm-controls]');

if (controls) {
    const enableButton = controls.querySelector('[data-fcm-enable]');
    const disableButton = controls.querySelector('[data-fcm-disable]');
    const status = controls.querySelector('[data-fcm-status]');
    const registeredTokenKey = 'quickpay:fcm:registered-token';
    const pushEnvironment = inspectPushEnvironment(window, navigator);
    const firebaseConfig = {
        apiKey: controls.dataset.firebaseApiKey,
        appId: controls.dataset.firebaseAppId,
        projectId: controls.dataset.firebaseProjectId,
        messagingSenderId: controls.dataset.firebaseSenderId,
    };
    const notificationIcon = new URL('/icons/icon-192.png', window.location.origin).href;
    let app;
    let messaging;
    let serviceWorkerRegistration;
    let registeredToken = null;

    try {
        registeredToken = localStorage.getItem(registeredTokenKey);
    } catch {
        registeredToken = null;
    }

    const setStatus = (message) => {
        status.textContent = message;
        status.hidden = false;
    };

    const setButtons = (enabled) => {
        enableButton.hidden = enabled;
        disableButton.hidden = !enabled;
    };

    const reportFailure = (stage, error) => {
        const code = error instanceof UserFacingError
            ? error.code
            : typeof error?.code === 'string'
                ? error.code
                : error instanceof TypeError
                    ? 'network-error'
                    : 'client-error';

        console.warn('Quick PayMoney push notification failure.', { stage, code });

        if (code === 'messaging/permission-blocked') {
            return 'Notifications are blocked. Allow notifications for this site in your browser or device settings, then try again.';
        }

        if (error instanceof UserFacingError) {
            return error.message;
        }

        const firebaseMessages = {
            'messaging/permission-blocked': 'Notifications are blocked. Allow notifications for this site in your browser or device settings, then try again.',
            'messaging/failed-service-worker-registration': 'The notification service worker could not be registered. Check the HTTPS certificate and /firebase-messaging/sw.js.',
            'messaging/token-subscribe-failed': 'Firebase could not create a notification token. Check the Firebase web app and VAPID configuration.',
        };

        if (firebaseMessages[code]) {
            return firebaseMessages[code];
        }

        if (stage === 'firebase-token') {
            return 'Firebase could not create a notification token. Check browser permission, Firebase configuration, and the VAPID key.';
        }

        if (stage === 'service-worker') {
            return 'The notification service worker could not start. Check the HTTPS certificate and /firebase-messaging/sw.js.';
        }

        if (stage === 'token-registration') {
            return 'The notification token could not be registered with this account. Check the Network response and sign-in session.';
        }

        return 'Push notifications could not be initialized. Check the HTTPS certificate, browser Console, and Firebase configuration.';
    };

    const saveRegisteredToken = (token) => {
        registeredToken = token;
        try {
            localStorage.setItem(registeredTokenKey, token);
        } catch {
            // The backend registration remains authoritative if browser storage is unavailable.
        }
    };

    const clearRegisteredToken = () => {
        registeredToken = null;
        try {
            localStorage.removeItem(registeredTokenKey);
        } catch {
            // The backend registration has already been removed.
        }
    };

    const hasRequiredConfiguration = () => Object.values(firebaseConfig).every(Boolean)
        && Boolean(controls.dataset.firebaseVapidKey);

    const getMessagingClient = async () => {
        const environmentMessage = getPushEnvironmentMessage(pushEnvironment);
        if (environmentMessage) {
            throw new UserFacingError(environmentMessage.message, environmentMessage.code);
        }

        if (!hasRequiredConfiguration()) {
            throw new UserFacingError('Push notifications are not configured yet. Check the deployed Firebase web configuration.', 'missing-firebase-config');
        }

        if (!await isSupported()) {
            const message = pushEnvironment.isIos
                ? 'This installed iPhone web app cannot use Web Push on this iOS version. Update to iOS 16.4 or newer and open the app from the Home Screen.'
                : 'Firebase Web Push is not supported in this browser. Use a current browser with notification and service worker support.';
            throw new UserFacingError(message, 'unsupported-browser');
        }

        app ??= getApps().find((candidate) => candidate.name === 'quickpay-messaging')
            ?? initializeApp(firebaseConfig, 'quickpay-messaging');
        messaging ??= getMessaging(app);
        if (!serviceWorkerRegistration) {
            try {
                serviceWorkerRegistration = await navigator.serviceWorker.register(
                    controls.dataset.serviceWorkerUrl,
                    { scope: '/firebase-messaging/' },
                );
            } catch {
                throw new UserFacingError('The notification service worker could not be registered. Check the HTTPS certificate and /firebase-messaging/sw.js.', 'service-worker-registration-failed');
            }
        }

        return messaging;
    };

    const responseError = (status) => {
        const responses = {
            401: ['Your sign-in session has expired. Sign in again, then retry.', 'http-401'],
            403: ['This account is not allowed to register notifications.', 'http-403'],
            404: ['The token registration route was not found. Check the deployed Laravel routes.', 'http-404'],
            409: ['This browser could not be registered to this account.', 'http-409'],
            419: ['Your security token or session expired. Refresh the page, sign in again, and retry.', 'http-419'],
            422: ['The server rejected the notification registration data.', 'http-422'],
        };
        const [message, code] = responses[status] ?? (status >= 500
            ? ['The server could not save this notification token. Check the Laravel log.', `http-${status}`]
            : ['The token registration request failed. Check the browser Network panel.', `http-${status}`]);

        return new UserFacingError(message, code);
    };

    const sendToken = async (url, method, token, previousToken = null, deviceLabel = null) => {
        let response;
        try {
            response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': controls.dataset.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    token,
                    ...(previousToken ? { previous_token: previousToken } : {}),
                    ...(deviceLabel ? { device_label: deviceLabel } : {}),
                }),
            });
        } catch {
            throw new UserFacingError('The same-origin token request could not reach this site. Check the HTTPS certificate and browser Network panel.', 'network-error');
        }

        if (!response.ok) {
            throw responseError(response.status);
        }

        if (response.redirected || new URL(response.url).origin !== window.location.origin) {
            throw new UserFacingError('The registration request was redirected away from this site. Refresh the page and sign in again.', 'unexpected-redirect');
        }

        const contentType = response.headers.get('content-type') ?? '';
        if (!contentType.includes('application/json')) {
            throw new UserFacingError('The server did not confirm notification registration. Refresh the page and sign in again.', 'invalid-server-response');
        }

        let result;
        try {
            result = await response.json();
        } catch {
            throw new UserFacingError('The server returned an invalid notification registration response.', 'invalid-server-response');
        }

        const expectedStatus = method === 'POST' ? 'registered' : 'unregistered';
        if (result.status !== expectedStatus) {
            throw new UserFacingError('The server did not confirm notification registration. Check the Laravel log.', 'registration-not-confirmed');
        }
    };

    enableButton.addEventListener('click', async () => {
        let stage = 'browser-permission';
        const environmentMessage = getPushEnvironmentMessage(pushEnvironment);
        if (environmentMessage) {
            setStatus(environmentMessage.message);
            return;
        }

        if (!hasRequiredConfiguration()) {
            setStatus('Push notifications are not configured yet. Please try again later.');
            return;
        }

        try {
            const permission = Notification.permission === 'default'
                ? await Notification.requestPermission()
                : Notification.permission;

            const permissionMessage = getNotificationPermissionMessage(permission);
            if (permissionMessage) {
                setStatus(permissionMessage);
                return;
            }

            stage = 'firebase-initialization';
            const client = await getMessagingClient();
            stage = 'firebase-token';
            const token = await getToken(client, {
                vapidKey: controls.dataset.firebaseVapidKey,
                serviceWorkerRegistration,
            });

            if (!token) {
                throw new UserFacingError('Firebase did not provide a notification token. Check browser permission and Firebase/VAPID configuration.', 'empty-firebase-token');
            }

            const previousToken = registeredToken;
            stage = 'token-registration';
            await sendToken(
                controls.dataset.registerUrl,
                'POST',
                token,
                previousToken && previousToken !== token ? previousToken : null,
                navigator.userAgent.slice(0, 120),
            );
            saveRegisteredToken(token);
            setButtons(true);
            setStatus('Notifications are enabled for this browser.');
        } catch (error) {
            setStatus(reportFailure(stage, error));
        }
    });

    disableButton.addEventListener('click', async () => {
        let stage = 'firebase-initialization';
        try {
            let client;
            let token = registeredToken;

            if (pushEnvironment.hasNotificationApi && Notification.permission === 'granted' && hasRequiredConfiguration()) {
                try {
                    client = await getMessagingClient();
                    stage = 'firebase-token';
                    token = await getToken(client, {
                        vapidKey: controls.dataset.firebaseVapidKey,
                        serviceWorkerRegistration,
                    }) || token;
                } catch (error) {
                    console.warn('Quick PayMoney push notification failure.', {
                        stage,
                        code: typeof error?.code === 'string' ? error.code : 'client-error',
                    });
                    if (!token) {
                        throw new UserFacingError('Could not retrieve this browser notification token.', 'missing-browser-token');
                    }
                }
            }

            if (!token) {
                setStatus('No notification token is registered for this browser. Enable notifications again to subscribe.');
                setButtons(false);
                return;
            }

            stage = 'token-registration';
            await sendToken(controls.dataset.unregisterUrl, 'DELETE', token);
            clearRegisteredToken();
            setButtons(false);

            try {
                if (client) {
                    await deleteToken(client);
                }
                setStatus('Notifications are disabled for this browser. Browser permission can be changed in its settings.');
            } catch (error) {
                console.warn('Quick PayMoney push notification cleanup failure.', {
                    stage: 'firebase-token-cleanup',
                    code: typeof error?.code === 'string' ? error.code : 'client-error',
                });
                setStatus('Notifications are disabled for this account, but browser cleanup did not finish. You can enable them again if needed.');
            }
        } catch (error) {
            setStatus(reportFailure(stage, error));
        }
    });

    void (async () => {
        setButtons(false);

        const environmentMessage = getPushEnvironmentMessage(pushEnvironment);
        if (environmentMessage) {
            setStatus(environmentMessage.message);
            enableButton.disabled = environmentMessage.code === 'unsupported-browser' || environmentMessage.code === 'https-required';
            return;
        }

        try {
            const client = await getMessagingClient();
            onMessage(client, async (payload) => {
                if (Notification.permission === 'granted') {
                    await serviceWorkerRegistration.showNotification(payload.data?.title ?? payload.notification?.title ?? 'Quick PayMoney', {
                        body: payload.data?.body ?? payload.notification?.body ?? 'You have a new notification.',
                        tag: payload.data?.tag ?? 'quickpay-push',
                        icon: notificationIcon,
                        data: { url: payload.data?.url ?? '/profile' },
                    });
                }
            });
        } catch (error) {
            enableButton.disabled = false;
            setStatus(reportFailure('firebase-initialization', error));
            return;
        }

        if (registeredToken && pushEnvironment.hasNotificationApi && Notification.permission === 'granted') {
            let stage = 'firebase-token';
            try {
                const token = await getToken(messaging, {
                    vapidKey: controls.dataset.firebaseVapidKey,
                    serviceWorkerRegistration,
                });
                if (!token) {
                    throw new UserFacingError('Firebase did not provide a notification token. Enable notifications again to subscribe.', 'empty-firebase-token');
                }

                stage = 'token-registration';
                await sendToken(
                    controls.dataset.registerUrl,
                    'POST',
                    token,
                    registeredToken !== token ? registeredToken : null,
                    navigator.userAgent.slice(0, 120),
                );
                saveRegisteredToken(token);
                setButtons(true);
            } catch (error) {
                setButtons(false);
                setStatus(reportFailure(stage, error));
            }
        }
    })();
}
