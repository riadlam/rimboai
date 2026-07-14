/**
 * Normalize media URLs for <img>/<audio>.
 * Keeps external CDNs; rewrites localhost → current origin.
 */
export function publicAsset(url?: string | null): string | null {
    if (!url) return null;
    const trimmed = url.trim();
    if (!trimmed) return null;

    if (!/^https?:\/\//i.test(trimmed)) {
        const path = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
        if (typeof window !== 'undefined' && window.location?.origin) {
            return `${window.location.origin}${path}`;
        }
        return path;
    }

    try {
        const parsed = new URL(trimmed);
        const localHosts = new Set(['localhost', '127.0.0.1', '::1', '0.0.0.0']);
        const path = `${parsed.pathname}${parsed.search}`;

        if (localHosts.has(parsed.hostname.toLowerCase())) {
            if (typeof window !== 'undefined' && window.location?.origin) {
                return `${window.location.origin}${path}`;
            }
            return path;
        }

        return trimmed;
    } catch {
        return trimmed;
    }
}
