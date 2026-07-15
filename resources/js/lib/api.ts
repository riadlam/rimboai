function getCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** Base origin from env (production build) or same-origin when empty. */
function apiBase(): string {
    const fromEnv = (import.meta.env.VITE_APP_URL as string | undefined)?.trim();
    if (fromEnv) return fromEnv.replace(/\/$/, '');
    return '';
}

/** Turn `/lab/...` into an absolute URL when VITE_APP_URL is set; otherwise keep relative. */
export function apiUrl(path: string): string {
    if (/^https?:\/\//i.test(path)) return path;
    const base = apiBase();
    const normalized = path.startsWith('/') ? path : `/${path}`;
    return base ? `${base}${normalized}` : normalized;
}

export class ApiError extends Error {
    status: number;
    payload: Record<string, unknown> | null;

    constructor(message: string, status: number, payload: Record<string, unknown> | null = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.payload = payload;
    }
}

function messageFromPayload(payload: unknown, status: number): string {
    let message =
        payload && typeof payload === 'object' && 'message' in payload && typeof (payload as { message: unknown }).message === 'string'
            ? (payload as { message: string }).message
            : `Request failed (${status})`;

    // Prefer the first Laravel validation error when present.
    if (payload && typeof payload === 'object' && 'errors' in payload) {
        const errors = (payload as { errors?: Record<string, string[]> }).errors;
        if (errors && typeof errors === 'object') {
            const first = Object.values(errors)
                .flat()
                .find((m) => typeof m === 'string' && m.trim() !== '');
            if (first) message = first;
        }
    }

    return message;
}

async function request<T>(url: string, method: 'GET' | 'POST', body?: unknown): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const token = getCookie('XSRF-TOKEN');
        if (token) headers['X-XSRF-TOKEN'] = token;
    }

    const res = await fetch(apiUrl(url), {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    let payload: unknown = null;
    try {
        payload = await res.json();
    } catch {
        // Non-JSON response (e.g. HTML error page) — leave payload null.
    }

    if (!res.ok) {
        throw new ApiError(
            messageFromPayload(payload, res.status),
            res.status,
            payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : null,
        );
    }

    return payload as T;
}

export function apiPostForm<T>(url: string, form: FormData): Promise<T> {
    return requestForm<T>(url, form);
}

async function requestForm<T>(url: string, form: FormData): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = getCookie('XSRF-TOKEN');
    if (token) headers['X-XSRF-TOKEN'] = token;

    const res = await fetch(apiUrl(url), {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body: form,
    });

    let payload: unknown = null;
    try {
        payload = await res.json();
    } catch {
        // Non-JSON response — leave payload null.
    }

    if (!res.ok) {
        throw new ApiError(
            messageFromPayload(payload, res.status),
            res.status,
            payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : null,
        );
    }

    return payload as T;
}

export function apiPost<T>(url: string, body?: unknown): Promise<T> {
    return request<T>(url, 'POST', body);
}

export function apiGet<T>(url: string): Promise<T> {
    return request<T>(url, 'GET');
}
