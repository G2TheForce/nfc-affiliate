// Web NFC is only in Chrome on Android, so it has no TypeScript lib types.
interface NdefWriter {
    write(message: { records: { recordType: 'url'; data: string }[] }, options?: { signal?: AbortSignal }): Promise<void>;
}

declare global {
    interface Window {
        NDEFReader?: new () => NdefWriter;
    }
}

/** True on phones that can write NFC tags from the browser (Chrome on Android, over HTTPS). */
export const canWriteNfc = () => typeof window !== 'undefined' && 'NDEFReader' in window && window.isSecureContext;

/**
 * Writes a URL to the next NFC tag held against the phone. Resolves once
 * written; rejects if the person cancels, denies permission or the tag is
 * locked. Gives up after a minute.
 */
export async function writeUrlToTag(url: string): Promise<void> {
    if (!window.NDEFReader) throw new Error('NFC writing is not supported on this device.');
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 60_000);
    try {
        await new window.NDEFReader().write({ records: [{ recordType: 'url', data: url }] }, { signal: controller.signal });
    } finally {
        window.clearTimeout(timer);
    }
}
