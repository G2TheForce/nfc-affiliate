import QRCode from 'qrcode';
import { useEffect, useState } from 'react';

/** A QR code for a link, drawn locally (the link is never sent to a QR service). */
export function QrCode({ value, size = 200, label, download }: { value: string; size?: number; label: string; download?: string }) {
    const [src, setSrc] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        QRCode.toDataURL(value, { width: size * 2, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0b1026', light: '#ffffff' } })
            .then((url) => active && setSrc(url))
            .catch(() => active && setSrc(null));
        return () => {
            active = false;
        };
    }, [value, size]);

    if (!src) return <div style={{ width: size, height: size }} className="animate-pulse rounded-xl bg-canvas" />;
    return (
        <div className="inline-flex flex-col items-center gap-2">
            <img src={src} width={size} height={size} alt={label} className="rounded-xl border border-line bg-white p-2" />
            {download && (
                <a href={src} download={download} className="text-sm font-semibold text-brand-600 hover:underline">
                    Download QR code
                </a>
            )}
        </div>
    );
}
