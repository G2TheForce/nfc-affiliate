import { useEffect, useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router';
import { CardArt, EmptyState, HeroScene, Illustration } from '@/components/brand';
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
    const isHome = useLocation().pathname === '/';
    const category = params.get('category') ?? '';
    const [products, setProducts] = useState<Product[] | null>(null);
    const [categories, setCategories] = useState<Category[]>([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: Category[] }>('/store/categories')
            .then((r) => setCategories(r.data))
            .catch(() => undefined);
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
        <div className="space-y-10">
            {isHome && <Hero />}
            <div id="products">
                {isHome ? (
                    <h2 className="text-2xl font-semibold text-ink">Shop smart NFC cards</h2>
                ) : (
                    <h1 className="text-2xl font-semibold text-ink">Smart NFC cards</h1>
                )}
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
            {products && products.length === 0 && <EmptyState title="No products here yet">Try another category, or check back soon.</EmptyState>}
            {products && products.length > 0 && (
                <ul className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {products.map((p, i) => (
                        <li
                            key={p.id}
                            className="animate-rise"
                            style={{
                                animationDelay: `${Math.min(i, 8) * 40}ms`,
                            }}
                        >
                            <Link
                                to={`/shop/${p.slug}`}
                                className="group block h-full overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition hover:-translate-y-1 hover:border-brand-500 hover:shadow-lg hover:shadow-primary/10"
                            >
                                <div className="bg-canvas p-5">
                                    {p.image_url ? (
                                        <img src={p.image_url} alt="" loading="lazy" className="aspect-[400/252] w-full rounded-xl object-contain" />
                                    ) : (
                                        <div className="rounded-xl shadow-lg transition group-hover:shadow-xl">
                                            <CardArt seed={p.slug} title={p.name} subtitle={p.category?.name} />
                                        </div>
                                    )}
                                </div>
                                <div className="p-5 pt-4">
                                    {p.category && <p className="text-xs font-medium uppercase tracking-wide text-muted">{p.category.name}</p>}
                                    <h2 className="mt-1 font-semibold text-ink">{p.name}</h2>
                                    <p className="mt-2 line-clamp-2 text-sm text-muted">{p.description}</p>
                                    <p className="mt-3 flex items-center justify-between font-semibold text-ink">
                                        {formatMoney(p.price_minor, p.currency)}
                                        <span className="text-sm text-brand-600 opacity-0 transition group-hover:opacity-100" aria-hidden>
                                            View →
                                        </span>
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const STEPS = [
    {
        kind: 'tap' as const,
        title: 'Tap',
        body: 'Hold the card near any modern phone. No app is needed on their side.',
    },
    {
        kind: 'share' as const,
        title: 'Share',
        body: 'Your contact details, links and socials open instantly, ready to save.',
    },
    {
        kind: 'earn' as const,
        title: 'Grow',
        body: 'Make a lasting first impression, and earn when you recommend us.',
    },
];

function Hero() {
    return (
        <>
            <section className="bg-night-sky relative -mx-4 -mt-6 overflow-hidden px-4 py-12 text-white sm:mx-0 sm:mt-0 sm:rounded-3xl sm:px-10 sm:py-14">
                <div className="grid items-center gap-10 lg:grid-cols-2">
                    <div className="space-y-5">
                        <p className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-cyan-200">
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-signal-cyan" />
                            Contactless networking, made in Nepal
                        </p>
                        <h1 className="text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
                            One tap.
                            <br />
                            <span className="text-signal">Every connection.</span>
                        </h1>
                        <p className="max-w-md text-slate-300">
                            Smart NFC business cards that share your contact, socials and website with a single tap. Never run out of paper cards again.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <a
                                href="#products"
                                className="inline-flex min-h-11 items-center rounded-lg bg-white px-5 font-semibold text-slate-900 hover:bg-cyan-50"
                            >
                                Shop cards
                            </a>
                            <Link
                                to="/affiliates"
                                className="inline-flex min-h-11 items-center rounded-lg border border-white/25 px-5 font-semibold text-white hover:bg-white/10"
                            >
                                Earn as an affiliate
                            </Link>
                        </div>
                        <dl className="flex gap-8 pt-2 text-sm">
                            <div>
                                <dt className="text-slate-400">Works with</dt>
                                <dd className="font-semibold">iPhone and Android</dd>
                            </div>
                            <div>
                                <dt className="text-slate-400">Pay with</dt>
                                <dd className="font-semibold">eSewa, Khalti, COD</dd>
                            </div>
                        </dl>
                    </div>
                    <HeroScene />
                </div>
            </section>
            <section aria-labelledby="how-heading" className="space-y-4">
                <h2 id="how-heading" className="text-center text-xl font-semibold text-ink">
                    How it works
                </h2>
                <ol className="grid gap-4 sm:grid-cols-3">
                    {STEPS.map((s, i) => (
                        <li key={s.title} className="flex flex-col items-center rounded-2xl border border-line bg-surface p-6 text-center shadow-sm">
                            <Illustration kind={s.kind} />
                            <p className="mt-3 font-semibold text-ink">
                                <span className="text-signal">{i + 1}.</span> {s.title}
                            </p>
                            <p className="mt-1 text-sm text-muted">{s.body}</p>
                        </li>
                    ))}
                </ol>
            </section>
        </>
    );
}
