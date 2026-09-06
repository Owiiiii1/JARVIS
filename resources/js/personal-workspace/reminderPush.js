export function pushSupported() {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function notificationPermission() {
    if (!pushSupported()) {
        return 'unsupported';
    }

    return Notification.permission;
}

export async function enableReminderPush({ vapidPublicKey, subscribeUrl, csrfToken }) {
    if (!pushSupported()) {
        return { state: 'unsupported' };
    }

    if (!vapidPublicKey) {
        return { state: 'unsupported' };
    }

    if (Notification.permission === 'denied') {
        return { state: 'denied' };
    }

    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return { state: permission === 'denied' ? 'denied' : 'disabled' };
    }

    const registration = await navigator.serviceWorker.register('/reminder-sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;

    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });

    const payload = subscription.toJSON();
    const response = await fetch(subscribeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            endpoint: payload.endpoint,
            keys: payload.keys,
            user_agent: navigator.userAgent,
        }),
    });

    if (!response.ok) {
        throw new Error('Не удалось сохранить подписку на уведомления.');
    }

    return { state: 'enabled', subscription };
}

export async function currentPushState() {
    if (!pushSupported()) {
        return 'unsupported';
    }

    if (Notification.permission === 'denied') {
        return 'denied';
    }

    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = await registration?.pushManager.getSubscription();

    if (Notification.permission === 'granted' && subscription) {
        return 'enabled';
    }

    return 'disabled';
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}
