/**
 * Thin fetch wrapper for the first-party SPA. Authentication uses the
 * Sanctum session cookie; state-changing requests send the XSRF token.
 */

export type ValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: ValidationErrors = {},
    ) {
        super(message);
    }
}

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}

let csrfReady: Promise<void> | null = null;

async function ensureCsrfCookie(refresh = false): Promise<void> {
    if (!refresh && readCookie('XSRF-TOKEN')) return;
    csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);
    await csrfReady;
    csrfReady = null;
}

type ApiOptions = { method?: string; body?: unknown; headers?: Record<string, string> };

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
    return request<T>(path, options, false);
}

async function request<T>(path: string, options: ApiOptions, retried: boolean): Promise<T> {
    const method = (options.method ?? 'GET').toUpperCase();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    if (method !== 'GET') {
        await ensureCsrfCookie();
        const token = readCookie('XSRF-TOKEN');
        if (token) headers['X-XSRF-TOKEN'] = token;
    }
    // FormData (file uploads) sets its own multipart Content-Type.
    const isForm = options.body instanceof FormData;
    if (options.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
    });

    // 419: the XSRF cookie is stale (expired session, or one issued under an
    // older APP_KEY). Fetch a fresh one and retry once.
    if (response.status === 419 && method !== 'GET' && !retried) {
        await ensureCsrfCookie(true);
        return request<T>(path, options, true);
    }

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            (data && typeof data.message === 'string' && data.message) ||
            (response.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : 'Something went wrong. Please try again.');
        throw new ApiError(response.status, message, (data && data.errors) || {});
    }

    return data as T;
}
