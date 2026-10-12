import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { AssetCard, type MarketingAsset } from '@/marketing/AssetCard';

export default function MarketingPage() {
    const [assets, setAssets] = useState<MarketingAsset[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: MarketingAsset[] }>('/affiliate/marketing-assets')
            .then((r) => setAssets(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load marketing materials.'));
    }, []);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!assets) return <Spinner />;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Marketing materials</h1>
            <p className="text-sm text-muted">
                Banners, photos and text from our team. Share them with your{' '}
                <Link to="/account/affiliate/links" className="text-brand-600 hover:underline">
                    referral link
                </Link>{' '}
                so orders are credited to you. The programme terms apply: no spam, fake reviews or misleading claims.
            </p>
            {assets.length === 0 ? (
                <Card>
                    <p className="text-sm text-muted">No materials yet. Check back soon.</p>
                </Card>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {assets.map((a) => (
                        <AssetCard key={a.id} asset={a} filePath={`/affiliate/marketing-assets/${a.id}/file`} />
                    ))}
                </ul>
            )}
        </div>
    );
}
