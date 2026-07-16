import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        Echo?: any;
    }
}

window.Pusher = Pusher;

const key = import.meta.env.VITE_PUSHER_APP_KEY as string | undefined;
const cluster = (import.meta.env.VITE_PUSHER_APP_CLUSTER as string | undefined) || 'eu';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getEcho(): any | null {
    if (!key) {
        return null;
    }

    if (!window.Echo) {
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster,
            forceTLS: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-XSRF-TOKEN': getXsrfToken(),
                    Accept: 'application/json',
                },
            },
        });
    }

    return window.Echo;
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    if (!match) return '';
    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
}
