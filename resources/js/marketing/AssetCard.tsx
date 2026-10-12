import { useEffect, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui';
import { downloadFile, fetchObjectUrl } from '@/lib/download';
import { formatBytes } from '@/lib/format';

export interface MarketingAsset {
    id: string;
    title: string;
    description: string | null;
    kind: 'image' | 'video' | 'document' | 'archive' | 'text';
    caption: string | null;
    has_file: boolean;
    mime: string | null;
    size_bytes: number | null;
    campaign: { id: string; name: string } | null;
    product: { id: string; name: string; slug: string } | null;
    created_at: string | null;
    is_active?: boolean;
    original_name?: string | null;
    download_count?: number;
}

export const KIND_LABELS: Record<MarketingAsset['kind'], string> = {
    image: 'Image',
    video: 'Video',
    document: 'PDF',
    archive: 'ZIP file',
    text: 'Post text',
};

function Preview({ path }: { path: string }) {
    const [src, setSrc] = useState<string | null>(null);
    useEffect(() => {
        let url: string | null = null;
        let active = true;
        fetchObjectUrl(path)
            .then((u) => {
                url = u;
                if (active) setSrc(u);
            })
            .catch(() => undefined);
        return () => {
            active = false;
            if (url) URL.revokeObjectURL(url);
        };
    }, [path]);

    return src ? (
        <img src={src} alt="" className="h-40 w-full rounded-lg bg-canvas object-contain" />
    ) : (
        <div className="h-40 w-full animate-pulse rounded-lg bg-canvas" aria-hidden />
    );
}

/** One marketing asset: preview, details, copyable text and a download button. `filePath` is the API path of its file. */
export function AssetCard({ asset, filePath, actions }: { asset: MarketingAsset; filePath: string; actions?: ReactNode }) {
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const copy = () =>
        navigator.clipboard
            ?.writeText(asset.caption ?? '')
            .then(() => {
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            })
            .catch(() => undefined);

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-4">
            {asset.kind === 'image' && asset.has_file && <Preview path={`${filePath}?inline=1`} />}
            <div className="space-y-1">
                <p className="font-semibold text-ink">{asset.title}</p>
                <p className="text-xs text-muted">
                    {KIND_LABELS[asset.kind]}
                    {asset.size_bytes ? ` · ${formatBytes(asset.size_bytes)}` : ''}
                    {asset.product ? ` · ${asset.product.name}` : ''}
                    {asset.campaign ? ` · ${asset.campaign.name}` : ''}
                </p>
                {asset.description && <p className="whitespace-pre-line text-sm text-muted">{asset.description}</p>}
            </div>
            {asset.caption && <p className="whitespace-pre-line rounded-lg bg-canvas p-3 text-sm text-ink">{asset.caption}</p>}
            {error && <p className="text-sm text-danger">{error}</p>}
            <div className="mt-auto flex flex-wrap items-center gap-2">
                {asset.has_file && (
                    <Button type="button" onClick={() => downloadFile(filePath).catch(() => setError('The download failed. Please try again.'))}>
                        Download
                    </Button>
                )}
                {asset.caption && (
                    <Button type="button" variant="secondary" onClick={copy}>
                        {copied ? 'Copied' : 'Copy text'}
                    </Button>
                )}
                {actions}
            </div>
        </li>
    );
}
