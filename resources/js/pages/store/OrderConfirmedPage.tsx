import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Alert, Button, Card } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { cart } from '@/store/cart';
import type { CustomerOrder } from '@/store/types';
import { LAST_ORDER_KEY } from './CheckoutPage';

interface Method {
    key: string;
    label: string;
    minimum_minor: number;
}

interface PaymentStatus {
    paid: boolean;
    order_status: string;
    attempt: { provider: string; status: string; outcome: 'paid' | 'pending' | 'failed' | 'checking' } | null;
}

interface Redirect {
    type: 'form' | 'redirect';
    url: string;
    fields?: Record<string, string>;
}

function lastOrder(): CustomerOrder | null {
    try {
        return JSON.parse(sessionStorage.getItem(LAST_ORDER_KEY) ?? 'null');
    } catch {
        return null;
    }
}

/** Sends the browser to the provider: a signed form post (eSewa) or a page URL (Khalti). */
function go(redirect: Redirect) {
    if (!redirect.url.startsWith('https://')) throw new Error('Unexpected payment address.');
    if (redirect.type === 'redirect') {
        window.location.assign(redirect.url);
        return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = redirect.url;
    for (const [name, value] of Object.entries(redirect.fields ?? {})) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}

export default function OrderConfirmedPage() {
    const order = lastOrder();
    const [params] = useSearchParams();
    const returned = params.get('payment');
    const [methods, setMethods] = useState<Method[]>([]);
    const [status, setStatus] = useState<PaymentStatus | null>(null);
    const [starting, setStarting] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [polls, setPolls] = useState(0);

    const orderId = order?.id;
    const token = order?.access_token;
    const sameOrder = !params.get('order') || params.get('order') === orderId;

    useEffect(() => {
        if (orderId) cart.clear();
    }, [orderId]);

    useEffect(() => {
        api<{ data: Method[] }>('/store/payment-methods')
            .then((r) => setMethods(r.data))
            .catch(() => setMethods([]));
    }, []);

    // Ask the server where the payment stands; while the provider is still
    // processing, ask again every few seconds for about a minute.
    useEffect(() => {
        if (!orderId || !token || !sameOrder) return;
        let active = true;
        const timer = setTimeout(
            () => {
                api<{ data: PaymentStatus }>(`/store/orders/${orderId}/payment-status?access_token=${encodeURIComponent(token)}`)
                    .then((r) => {
                        if (!active) return;
                        setStatus(r.data);
                        const waiting = r.data.attempt && (r.data.attempt.outcome === 'pending' || r.data.attempt.outcome === 'checking');
                        if (waiting && !r.data.paid && polls < 20 && returned) setPolls((n) => n + 1);
                    })
                    .catch(() => undefined);
            },
            polls === 0 ? 0 : 3000,
        );
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [orderId, token, sameOrder, polls, returned]);

    const pay = async (provider: string) => {
        if (!order || !token) return;
        setError(null);
        setStarting(provider);
        try {
            const res = await api<{ data: { redirect: Redirect } }>(`/store/orders/${order.id}/payments`, {
                method: 'POST',
                body: { provider, access_token: token },
            });
            go(res.data.redirect);
        } catch (e) {
            const field = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] : undefined;
            setError(field ?? (e instanceof ApiError ? e.message : 'The payment could not be started. Please try again.'));
            setStarting(null);
        }
    };

    if (!order || !sameOrder)
        return (
            <Card title={returned ? 'Payment received by the provider' : 'No recent order'}>
                {returned && <p className="mb-3 text-sm text-muted">We could not find the order in this browser. If you paid, we will confirm it by email.</p>}
                <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                    Back to the shop
                </Link>
            </Card>
        );

    const paid = status?.paid ?? false;
    const outcome = status?.attempt?.outcome;
    // While the provider is still settling a payment, do not invite a second one.
    const confirming = !paid && !!returned && (outcome === 'pending' || outcome === 'checking') && polls < 20;
    const canPay = !paid && !confirming && status?.order_status === 'pending_payment' && token;
    const offered = methods.filter((m) => order.total_minor >= m.minimum_minor);

    return (
        <div className="mx-auto max-w-xl space-y-4">
            {paid && <Alert tone="success">Payment received. Thank you, we will email you a receipt.</Alert>}
            {confirming && <Alert>We are confirming your payment with {status?.attempt?.provider === 'khalti' ? 'Khalti' : 'eSewa'}. This page updates by itself.</Alert>}
            {!paid && !confirming && returned && (outcome === 'pending' || outcome === 'checking') && (
                <Alert>Your payment is not confirmed yet. If money left your wallet, do not pay again: we keep checking and will email you once it is confirmed.</Alert>
            )}
            {!paid && returned === 'failed' && outcome !== 'pending' && <Alert tone="error">The payment was not completed. You can try again below.</Alert>}

            <Card title={paid ? 'Your order is paid' : 'Thank you, your order is placed'}>
                <p className="text-sm text-muted">
                    Order number <strong className="text-ink">{order.order_number}</strong>.{' '}
                    {paid ? 'We will let you know when it is on its way.' : `We will contact you on ${order.customer_phone} about delivery.`}
                </p>
                <ul className="mt-4 divide-y divide-line text-sm">
                    {order.items.map((i) => (
                        <li key={i.sku} className="flex justify-between py-2">
                            <span>
                                {i.name} × {i.quantity}
                            </span>
                            <span className="tabular-nums">{formatMoney(i.line_total_minor, order.currency)}</span>
                        </li>
                    ))}
                </ul>
                <p className="mt-3 flex justify-between border-t border-line pt-3 font-semibold">
                    <span>Total</span>
                    <span className="tabular-nums">{formatMoney(order.total_minor, order.currency)}</span>
                </p>
            </Card>

            {canPay && (
                <Card title="Pay now">
                    {error && <Alert tone="error">{error}</Alert>}
                    {offered.length > 0 ? (
                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                            {offered.map((m) => (
                                <Button key={m.key} variant="secondary" loading={starting === m.key} disabled={starting !== null} onClick={() => pay(m.key)}>
                                    Pay with {m.label}
                                </Button>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted">Online payment is not available right now.</p>
                    )}
                    <p className="mt-3 text-xs text-muted">You can also pay by bank transfer or cash on delivery; we will contact you with the details.</p>
                </Card>
            )}

            <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                Continue shopping
            </Link>
        </div>
    );
}
