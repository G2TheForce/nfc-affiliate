import { useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui';
import { formatMoney } from '@/lib/format';
import { cart } from '@/store/cart';
import type { CustomerOrder } from '@/store/types';
import { LAST_ORDER_KEY } from './CheckoutPage';

function lastOrder(): CustomerOrder | null {
    try {
        return JSON.parse(sessionStorage.getItem(LAST_ORDER_KEY) ?? 'null');
    } catch {
        return null;
    }
}

export default function OrderConfirmedPage() {
    const order = lastOrder();

    const orderId = order?.id;
    useEffect(() => {
        if (orderId) cart.clear();
    }, [orderId]);

    if (!order)
        return (
            <Card title="No recent order">
                <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                    Back to the shop
                </Link>
            </Card>
        );

    return (
        <div className="mx-auto max-w-xl space-y-4">
            <Card title="Thank you, your order is placed">
                <p className="text-sm text-muted">
                    Order number <strong className="text-ink">{order.order_number}</strong>. We will contact you on {order.customer_phone} to confirm
                    payment and delivery.
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
            <Link to="/shop" className="text-sm font-semibold text-brand-600 hover:underline">
                Continue shopping
            </Link>
        </div>
    );
}
