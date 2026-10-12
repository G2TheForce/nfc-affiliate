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

async function ensureCsrfCookie(): Promise<void> {
    if (readCookie('XSRF-TOKEN')) return;
    csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);
    await csrfReady;
    csrfReady = null;
}

export async function api<T>(path: string, options: { method?: string; body?: unknown; headers?: Record<string, string> } = {}): Promise<T> {
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

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            (data && typeof data.message === 'string' && data.message) ||
            (response.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : 'Something went wrong. Please try again.');
        throw new ApiError(response.status, message, (data && data.errors) || {});
    }

    return data as T;
}
