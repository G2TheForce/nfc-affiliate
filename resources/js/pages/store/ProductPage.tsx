import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { CardArt, TiltCard } from '@/components/brand';
import { Alert, Button, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { cart } from '@/store/cart';
import type { Product } from '@/store/types';

export default function ProductPage() {
    const { slug } = useParams();
    const navigate = useNavigate();
    const [product, setProduct] = useState<Product | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [quantity, setQuantity] = useState(1);
    const [previewName, setPreviewName] = useState('');

    useEffect(() => {
        let active = true;
        api<{ data: Product }>(`/store/products/${slug}`)
            .then((r) => active && setProduct(r.data))
            .catch((e) => active && setError(e instanceof ApiError && e.status === 404 ? 'This product is not available.' : 'Could not load the product.'));
        return () => {
            active = false;
        };
    }, [slug]);

    if (error)
        return (
            <div className="space-y-3">
                <Alert tone="error">{error}</Alert>
                <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                    Back to the shop
                </Link>
            </div>
        );
    if (!product) return <Spinner />;

    const add = () => {
        cart.add(
            {
                product_id: product.id,
                slug: product.slug,
                name: product.name,
                price_minor: product.price_minor,
                currency: product.currency,
            },
            quantity,
        );
        navigate('/cart');
    };

    return (
        <article className="space-y-4">
            <Link to="/shop" className="text-sm text-muted hover:text-ink">
                ← All products
            </Link>
            <div className="grid gap-8 lg:grid-cols-2">
                <div className="space-y-4">
                    <div className="bg-night-sky flex items-center justify-center rounded-3xl p-8 sm:p-12">
                        <TiltCard className="w-full max-w-md">
                            {product.image_url ? (
                                <img src={product.image_url} alt={product.name} className="aspect-[400/252] w-full bg-surface object-contain" />
                            ) : (
                                <CardArt
                                    seed={product.slug}
                                    title={previewName.trim() || product.name}
                                    subtitle={previewName.trim() ? product.name : product.category?.name}
                                />
                            )}
                        </TiltCard>
                    </div>
                    {!product.image_url && (
                        <div className="space-y-1">
                            <label htmlFor="preview-name" className="block text-sm font-medium text-ink">
                                Preview with your name
                            </label>
                            <input
                                id="preview-name"
                                value={previewName}
                                maxLength={40}
                                placeholder="e.g. Sita Sharma"
                                onChange={(e) => setPreviewName(e.target.value)}
                                className="block min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-base text-ink sm:text-sm"
                            />
                            <p className="text-xs text-muted">A preview only. The printed design may differ.</p>
                        </div>
                    )}
                </div>
                <div className="space-y-4">
                    {product.category && <p className="text-xs font-medium uppercase tracking-wide text-muted">{product.category.name}</p>}
                    <h1 className="text-3xl font-semibold tracking-tight text-ink">{product.name}</h1>
                    <p className="text-2xl font-semibold text-ink">{formatMoney(product.price_minor, product.currency)}</p>
                    {product.description && <p className="whitespace-pre-line text-muted">{product.description}</p>}
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1">
                            <label htmlFor="qty" className="block text-sm font-medium text-ink">
                                Quantity
                            </label>
                            <input
                                id="qty"
                                type="number"
                                min={1}
                                max={100}
                                value={quantity}
                                onChange={(e) => setQuantity(Math.max(1, Math.min(100, Number(e.target.value) || 1)))}
                                className="block min-h-11 w-24 rounded-lg border border-line bg-surface px-3 text-base text-ink sm:text-sm"
                            />
                        </div>
                        <Button onClick={add}>Add to cart</Button>
                    </div>
                    <ul className="grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-2">
                        {FEATURES.map((f) => (
                            <li key={f.title} className="flex gap-3">
                                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden>
                                    ✓
                                </span>
                                <span>
                                    <span className="block font-medium text-ink">{f.title}</span>
                                    <span className="text-muted">{f.body}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </article>
    );
}

const FEATURES = [
    {
        title: 'No app needed',
        body: 'Works with iPhone and Android phones that support NFC.',
    },
    {
        title: 'Delivered across Nepal',
        body: 'Track your order from your account.',
    },
    { title: 'Pay your way', body: 'eSewa, Khalti or cash on delivery.' },
    {
        title: 'Secure checkout',
        body: 'Online payments are confirmed with eSewa or Khalti directly.',
    },
];
