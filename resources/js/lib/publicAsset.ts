/**
 * Rewrite localhost / absolute same-host media URLs to relative paths
 * so samples and icons work in production without baking APP_URL.
 */
export function publicAsset(url?: string | null): string | null {
    if (!url) return null;
    const trimmed = url.trim();
    if (!trimmed) return null;

    if (!/^https?:\/\//i.test(trimmed)) {
        return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
    }

    try {
        const parsed = new URL(trimmed);
        const localHosts = new Set(['localhost', '127.0.0.1', '::1', '0.0.0.0']);
        const path = `${parsed.pathname}${parsed.search}`;

        if (path.startsWith('/storage/')) {
            return path;
        }

        if (typeof window !== 'undefined' && parsed.hostname === window.location.hostname) {
            return path || null;
        }

        if (localHosts.has(parsed.hostname.toLowerCase())) {
            return path || null;
        }

        return trimmed;
    } catch {
        return trimmed;
    }
}
