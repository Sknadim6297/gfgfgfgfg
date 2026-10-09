self.addEventListener('push', (event) => {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch {
            payload = {};
        }
    }

    const notification = payload.notification ?? payload.data?.notification ?? {};
    const messageData = payload.data ?? {};
    const candidate = messageData.url ?? notification.data?.url ?? payload.fcmOptions?.link ?? '/profile';
    let url = '/profile';

    try {
        const parsed = new URL(candidate, self.location.origin);
        if (parsed.origin === self.location.origin && !parsed.username && !parsed.password) {
            url = `${parsed.pathname}${parsed.search}${parsed.hash}`;
        }
    } catch {
        url = '/profile';
    }

    const candidateIcon = notification.icon ?? messageData.icon ?? '/icons/icon-192.png';
    let icon = new URL('/icons/icon-192.png', self.location.origin).href;

    try {
        const parsedIcon = new URL(candidateIcon, self.location.origin);
        if (parsedIcon.origin === self.location.origin && !parsedIcon.username && !parsedIcon.password) {
            icon = parsedIcon.href;
        }
    } catch {
        icon = new URL('/icons/icon-192.png', self.location.origin).href;
    }

    event.waitUntil(self.registration.showNotification(
        notification.title ?? messageData.title ?? 'Quick PayMoney',
        {
            body: notification.body ?? messageData.body ?? 'You have a new notification.',
            tag: notification.tag ?? messageData.tag ?? 'quickpay-push',
            icon,
            data: { url },
        },
    ));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const candidate = event.notification.data?.url ?? '/profile';
    let target = new URL('/profile', self.location.origin);

    try {
        const parsed = new URL(candidate, self.location.origin);
        if (parsed.origin === self.location.origin && !parsed.username && !parsed.password) {
            target = parsed;
        }
    } catch {
        target = new URL('/profile', self.location.origin);
    }

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);

        if (existing) {
            try {
                await existing.navigate(target.href);
                await existing.focus();
                return;
            } catch {
                await self.clients.openWindow(target.href);
                return;
            }
        }

        await self.clients.openWindow(target.href);
    })());
});
