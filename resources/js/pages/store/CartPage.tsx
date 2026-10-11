import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { Alert, Button, Card } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { cart, useCart } from '@/store/cart';
import type { Quote } from '@/store/types';

export const COUPON_KEY = 'nfc-coupon';

export default function CartPage() {
    const lines = useCart();
    const navigate = useNavigate();
    const [coupon, setCoupon] = useState(() => sessionStorage.getItem(COUPON_KEY) ?? '');
    const [applied, setApplied] = useState(() => sessionStorage.getItem(COUPON_KEY) ?? '');
    const [quote, setQuote] = useState<Quote | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (lines.length === 0) return;
        let active = true;
        api<{ data: Quote }>('/store/cart/quote', {
            method: 'POST',
            body: { items: lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity })), coupon_code: applied || null },
        })
            .then((r) => active && (setQuote(r.data), setError(null)))
            .catch((e) => {
                if (!active) return;
                setError(e instanceof ApiError ? (e.errors.coupon_code?.[0] ?? e.errors.items?.[0] ?? e.message) : 'Could not price your cart.');
                if (e instanceof ApiError && e.errors.coupon_code) {
                    setApplied('');
                    sessionStorage.removeItem(COUPON_KEY);
                }
            });
        return () => {
            active = false;
        };
    }, [lines, applied]);

    const apply = (e: FormEvent) => {
        e.preventDefault();
        const code = coupon.trim().toUpperCase();
        setApplied(code);
        if (code) sessionStorage.setItem(COUPON_KEY, code);
        else sessionStorage.removeItem(COUPON_KEY);
    };

    if (lines.length === 0)
        return (
            <Card title="Your cart is empty">
                <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                    Browse products
                </Link>
            </Card>
        );

    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="min-w-0 space-y-3 lg:col-span-2">
                <h1 className="text-2xl font-semibold text-ink">Cart</h1>
                <ul className="divide-y divide-line rounded-xl border border-line bg-surface shadow-sm">
                    {lines.map((l) => (
                        <li key={l.product_id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div className="min-w-0">
                                <Link to={`/shop/${l.slug}`} className="font-medium text-ink hover:underline">
                                    {l.name}
                                </Link>
                                <p className="text-sm text-muted">{formatMoney(l.price_minor, l.currency)} each</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <label className="sr-only" htmlFor={`q-${l.product_id}`}>
                                    Quantity for {l.name}
                                </label>
                                <input
                                    id={`q-${l.product_id}`}
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={l.quantity}
                                    onChange={(e) => cart.setQuantity(l.product_id, Number(e.target.value) || 0)}
                                    className="min-h-11 w-20 rounded-lg border border-line px-3 text-base sm:text-sm"
                                />
                                <button type="button" onClick={() => cart.setQuantity(l.product_id, 0)} className="min-h-11 px-2 text-sm text-danger hover:underline">
                                    Remove
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="min-w-0 space-y-4">
                <Card title="Summary">
                    <form onSubmit={apply} className="mb-4 flex gap-2">
                        <label htmlFor="coupon" className="sr-only">
                            Discount code
                        </label>
                        <input
                            id="coupon"
                            value={coupon}
                            onChange={(e) => setCoupon(e.target.value)}
                            placeholder="Discount code"
                            className="min-h-11 min-w-0 flex-1 rounded-lg border border-line px-3 text-base uppercase sm:text-sm"
                        />
                        <Button type="submit" variant="secondary">
                            Apply
                        </Button>
                    </form>
                    {error && <Alert tone="error">{error}</Alert>}
                    {quote && (
                        <dl className="mt-3 space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted">Subtotal</dt>
                                <dd className="tabular-nums">{formatMoney(quote.subtotal_minor, quote.currency)}</dd>
                            </div>
                            {quote.discount_minor > 0 && (
                                <div className="flex justify-between text-success">
                                    <dt>Discount ({quote.coupon_code})</dt>
                                    <dd className="tabular-nums">−{formatMoney(quote.discount_minor, quote.currency)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <dt className="text-muted">Delivery</dt>
                                <dd className="tabular-nums">{quote.shipping_minor ? formatMoney(quote.shipping_minor, quote.currency) : 'Free'}</dd>
                            </div>
                            <div className="flex justify-between border-t border-line pt-2 font-semibold">
                                <dt>Total</dt>
                                <dd className="tabular-nums">{formatMoney(quote.total_minor, quote.currency)}</dd>
                            </div>
                        </dl>
                    )}
                    <Button className="mt-4 w-full" onClick={() => navigate('/checkout')} disabled={!quote}>
                        Checkout
                    </Button>
                </Card>
            </div>
        </div>
    );
}
