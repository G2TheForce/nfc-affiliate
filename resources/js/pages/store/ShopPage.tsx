import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Alert, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import type { Product } from '@/store/types';

interface Category {
    id: number;
    name: string;
    slug: string;
}

export default function ShopPage() {
    const [params, setParams] = useSearchParams();
    const category = params.get('category') ?? '';
    const [products, setProducts] = useState<Product[] | null>(null);
    const [categories, setCategories] = useState<Category[]>([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: Category[] }>('/store/categories').then((r) => setCategories(r.data)).catch(() => undefined);
    }, []);

    useEffect(() => {
        let active = true;
        api<{ data: Product[] }>(`/store/products${category ? `?category=${encodeURIComponent(category)}` : ''}`)
            .then((r) => active && (setProducts(r.data), setError(null)))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load products.'));
        return () => {
            active = false;
        };
    }, [category]);

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-semibold text-ink">Smart NFC cards</h1>
                <p className="mt-1 text-sm text-muted">Tap-to-share business cards, review stands and more, delivered across Nepal.</p>
            </div>
            {categories.length > 0 && (
                <div className="flex flex-wrap gap-2" aria-label="Categories">
                    {[{ slug: '', name: 'All' }, ...categories].map((c) => (
                        <button
                            key={c.slug || 'all'}
                            type="button"
                            aria-pressed={category === c.slug}
                            onClick={() => setParams(c.slug ? { category: c.slug } : {})}
                            className={`min-h-9 rounded-full border px-3 text-sm font-medium ${
                                category === c.slug ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-line bg-surface text-muted hover:text-ink'
                            }`}
                        >
                            {c.name}
                        </button>
                    ))}
                </div>
            )}
            {error && <Alert tone="error">{error}</Alert>}
            {!products && !error && <Spinner />}
            {products && products.length === 0 && <p className="text-sm text-muted">No products here yet.</p>}
            {products && products.length > 0 && (
                <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {products.map((p) => (
                        <li key={p.id}>
                            <Link to={`/shop/${p.slug}`} className="block h-full rounded-xl border border-line bg-surface p-5 shadow-sm hover:border-brand-500">
                                {p.category && <p className="text-xs font-medium uppercase tracking-wide text-muted">{p.category.name}</p>}
                                <h2 className="mt-1 font-semibold text-ink">{p.name}</h2>
                                <p className="mt-2 line-clamp-2 text-sm text-muted">{p.description}</p>
                                <p className="mt-3 font-semibold text-ink">{formatMoney(p.price_minor, p.currency)}</p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
