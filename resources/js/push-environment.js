export function inspectPushEnvironment(windowObject, navigatorObject, userAgent = navigatorObject.userAgent ?? '') {
    const platform = navigatorObject.userAgentData?.platform ?? navigatorObject.platform ?? '';
    const isIos = /iPhone|iPad|iPod/i.test(userAgent)
        || (/Mac/i.test(platform) && navigatorObject.maxTouchPoints > 1);
    let isStandalone = navigatorObject.standalone === true;

    try {
        isStandalone ||= windowObject.matchMedia?.('(display-mode: standalone)').matches === true;
    } catch {
        isStandalone ||= false;
    }

    return {
        isIos,
        isStandalone,
        hasNotificationApi: typeof windowObject.Notification?.requestPermission === 'function',
        hasServiceWorkerApi: typeof navigatorObject.serviceWorker?.register === 'function',
        isSecureContext: windowObject.isSecureContext === true,
    };
}

export function getPushEnvironmentMessage(environment) {
    if (environment.isIos && !environment.isStandalone) {
        return {
            code: 'ios-home-screen-required',
            message: 'On iPhone or iPad, open this site in Safari, tap Share > Add to Home Screen, then open the installed app and enable notifications. Web Push requires iOS 16.4 or newer.',
        };
    }

    if (!environment.isSecureContext) {
        return {
            code: 'https-required',
            message: 'Push notifications require a secure HTTPS connection. Open this site using its trusted HTTPS address.',
        };
    }

    if (!environment.hasNotificationApi || !environment.hasServiceWorkerApi) {
        return {
            code: 'unsupported-browser',
            message: 'This browser does not support web push notifications. Use a supported, up-to-date browser; on iPhone, install this site from Safari first.',
        };
    }

    return null;
}

export function getNotificationPermissionMessage(permission) {
    if (permission === 'denied') {
        return 'Notifications are blocked. Allow notifications for this site in your browser or device settings, then try again.';
    }

    if (permission !== 'granted') {
        return 'Notification permission was not granted.';
    }

    return null;
}
