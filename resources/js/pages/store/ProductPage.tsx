import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
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
        cart.add({ product_id: product.id, slug: product.slug, name: product.name, price_minor: product.price_minor, currency: product.currency }, quantity);
        navigate('/cart');
    };

    return (
        <article className="mx-auto max-w-2xl space-y-4 rounded-xl border border-line bg-surface p-6 shadow-sm">
            <Link to="/shop" className="text-sm text-muted hover:text-ink">
                ← All products
            </Link>
            {product.image_url && <img src={product.image_url} alt={product.name} className="aspect-square w-full rounded-lg bg-canvas object-contain" />}
            {product.category && <p className="text-xs font-medium uppercase tracking-wide text-muted">{product.category.name}</p>}
            <h1 className="text-2xl font-semibold text-ink">{product.name}</h1>
            <p className="text-xl font-semibold text-ink">{formatMoney(product.price_minor, product.currency)}</p>
            {product.description && <p className="whitespace-pre-line text-sm text-muted">{product.description}</p>}
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
                        className="block min-h-11 w-24 rounded-lg border border-line bg-surface px-3 text-base sm:text-sm"
                    />
                </div>
                <Button onClick={add}>Add to cart</Button>
            </div>
        </article>
    );
}
