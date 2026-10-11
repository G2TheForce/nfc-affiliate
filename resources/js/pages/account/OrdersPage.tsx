import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert, Card, Spinner, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { CustomerOrder } from '@/store/types';

export default function OrdersPage() {
    const [orders, setOrders] = useState<CustomerOrder[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: CustomerOrder[] }>('/orders')
            .then((r) => setOrders(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load your orders.'));
    }, []);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!orders) return <Spinner />;

    return (
        <div className="space-y-4">
            <h1 className="text-2xl font-semibold text-ink">My orders</h1>
            {orders.length === 0 && (
                <Card>
                    <p className="text-sm text-muted">You have no orders yet.</p>
                    <Link to="/shop" className="mt-2 inline-block text-sm font-semibold text-brand-600 hover:underline">
                        Visit the shop
                    </Link>
                </Card>
            )}
            {orders.map((o) => (
                <Card key={o.id}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="font-semibold text-ink">{o.order_number}</p>
                            <p className="text-sm text-muted">{formatDateTime(o.placed_at)}</p>
                        </div>
                        <StatusBadge status={o.status} label={o.status_label} />
                    </div>
                    <ul className="mt-3 text-sm text-muted">
                        {o.items.map((i) => (
                            <li key={i.sku}>
                                {i.name} × {i.quantity}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-sm font-semibold text-ink">Total {formatMoney(o.total_minor, o.currency)}</p>
                    {o.fulfilment_status && o.fulfilment_status !== 'unfulfilled' && (
                        <p className="mt-1 text-sm text-muted">
                            {o.fulfilment_label}
                            {o.carrier && ` with ${o.carrier}`}
                            {o.tracking_number && `, tracking number ${o.tracking_number}`}
                            {o.delivered_at ? ` on ${formatDateTime(o.delivered_at)}` : o.shipped_at ? ` on ${formatDateTime(o.shipped_at)}` : ''}
                        </p>
                    )}
                </Card>
            ))}
        </div>
    );
}
