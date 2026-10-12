import { useRef, useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, TextArea } from '@/components/ui';
import { api } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { useCart } from '@/store/cart';
import type { CustomerOrder } from '@/store/types';
import { COUPON_KEY } from './CartPage';

export const LAST_ORDER_KEY = 'nfc-last-order';

export default function CheckoutPage() {
    const { user } = useAuth();
    const lines = useCart();
    const navigate = useNavigate();
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const [submitting, setSubmitting] = useState(false);
    // One key per checkout attempt: a double click or a retry after a
    // network error cannot create a second order.
    const idempotencyKey = useRef(crypto.randomUUID());
    const [form, setForm] = useState({
        customer_name: user?.name ?? '',
        customer_email: user?.email ?? '',
        customer_phone: user?.phone ?? '+977',
        shipping_city: '',
        shipping_address: '',
        referral_code: '',
    });

    if (lines.length === 0) return <Navigate to="/cart" replace />;

    const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));
    const estimate = lines.reduce((n, l) => n + l.price_minor * l.quantity, 0);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSubmitting(true);
        try {
            const res = await api<{ data: CustomerOrder; meta?: { access_token?: string } }>('/store/orders', {
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKey.current },
                body: {
                    ...form,
                    referral_code: form.referral_code.trim() || null,
                    coupon_code: sessionStorage.getItem(COUPON_KEY) || null,
                    items: lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity })),
                },
            });
            // The access token lets this browser pay for the order and see the result.
            sessionStorage.setItem(LAST_ORDER_KEY, JSON.stringify({ ...res.data, access_token: res.meta?.access_token }));
            sessionStorage.removeItem(COUPON_KEY);
            // The confirmation page empties the cart; doing it here would
            // re-render this page with an empty cart and bounce to /cart.
            navigate('/order-confirmed', { replace: true });
        } catch (err) {
            capture(err);
            setSubmitting(false);
        }
    };

    return (
        <div className="mx-auto max-w-xl space-y-4">
            <h1 className="text-2xl font-semibold text-ink">Checkout</h1>
            <Card>
                <form onSubmit={submit} className="space-y-4" noValidate>
                    {message && <Alert tone="error">{message}</Alert>}
                    {(fieldErrors.items || fieldErrors.coupon_code) && <Alert tone="error">{fieldErrors.items ?? fieldErrors.coupon_code}</Alert>}
                    <Field label="Full name" value={form.customer_name} onChange={set('customer_name')} error={fieldErrors.customer_name} autoComplete="name" required />
                    <Field
                        label="Email"
                        type="email"
                        value={form.customer_email}
                        onChange={set('customer_email')}
                        error={fieldErrors.customer_email}
                        autoComplete="email"
                        disabled={!!user}
                        hint={user ? 'Orders are linked to your account email.' : undefined}
                        required
                    />
                    <Field label="Phone" type="tel" value={form.customer_phone} onChange={set('customer_phone')} error={fieldErrors.customer_phone} autoComplete="tel" required />
                    <Field label="City" value={form.shipping_city} onChange={set('shipping_city')} error={fieldErrors.shipping_city} autoComplete="address-level2" required />
                    <TextArea label="Delivery address" value={form.shipping_address} onChange={set('shipping_address')} error={fieldErrors.shipping_address} autoComplete="street-address" rows={2} required />
                    <Field
                        label="Referral code (optional)"
                        value={form.referral_code}
                        onChange={set('referral_code')}
                        error={fieldErrors.referral_code}
                        hint="If someone recommended us, enter their code."
                    />
                    <p className="text-sm text-muted">
                        Estimated total {formatMoney(estimate)} before discounts. You will pay once we confirm your order; nothing is charged now.
                    </p>
                    <Button type="submit" loading={submitting} className="w-full">
                        Place order
                    </Button>
                    <Link to="/cart" className="block text-center text-sm text-muted hover:text-ink">
                        Back to cart
                    </Link>
                </form>
            </Card>
        </div>
    );
}
