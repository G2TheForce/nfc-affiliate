import { ApiError } from '@/lib/api';

/**
 * Downloads a file from the API with the session cookie. Errors (no
 * permission, too many exports) come back as JSON and are thrown as ApiError
 * instead of leaving the browser on an error page.
 */
export async function downloadFile(path: string): Promise<void> {
    const response = await fetch(`/api/v1${path}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        const data = await response.json().catch(() => null);
        const message =
            (data && typeof data.message === 'string' && data.message) ||
            (response.status === 429 ? 'Too many exports. Please wait a minute and try again.' : 'The export could not be created.');
        throw new ApiError(response.status, message, (data && data.errors) || {});
    }

    const disposition = response.headers.get('Content-Disposition') ?? '';
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'export';
    const url = URL.createObjectURL(await response.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Loads an image from the API with the session cookie and returns an object URL for an <img>. */
export async function fetchObjectUrl(path: string): Promise<string> {
    const response = await fetch(`/api/v1${path}`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) throw new ApiError(response.status, 'Could not load the preview.', {});
    return URL.createObjectURL(await response.blob());
}
