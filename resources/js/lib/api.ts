function getCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

export class ApiError extends Error {
    status: number;

    constructor(message: string, status: number) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
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

    const res = await fetch(url, {
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
        const message =
            (payload && typeof payload === 'object' && 'message' in payload && typeof (payload as { message: unknown }).message === 'string'
                ? (payload as { message: string }).message
                : null) || `Request failed (${res.status})`;
        throw new ApiError(message, res.status);
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

    const res = await fetch(url, {
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
        const message =
            (payload && typeof payload === 'object' && 'message' in payload && typeof (payload as { message: unknown }).message === 'string'
                ? (payload as { message: string }).message
                : null) || `Request failed (${res.status})`;
        throw new ApiError(message, res.status);
    }

    return payload as T;
}

export function apiPost<T>(url: string, body?: unknown): Promise<T> {
    return request<T>(url, 'POST', body);
}

export function apiGet<T>(url: string): Promise<T> {
    return request<T>(url, 'GET');
}
