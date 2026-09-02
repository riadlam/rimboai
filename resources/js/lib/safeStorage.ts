export function safeGetItem(key: string): string | null {
    if (typeof window === 'undefined') return null;
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

export function safeSetItem(key: string, value: string): void {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Restricted WebViews (e.g. Facebook in-app browser) may block storage.
    }
}

export function safeSetCookie(name: string, value: string, maxAgeSeconds = 60 * 60 * 24 * 365): void {
    if (typeof document === 'undefined') return;
    try {
        document.cookie = `${name}=${value};path=/;max-age=${maxAgeSeconds};SameSite=Lax`;
    } catch {
        // noop
    }
}
