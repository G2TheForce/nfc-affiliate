import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Card, Spinner, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { AdminOrder } from './orderTypes';

interface Page {
    data: AdminOrder[];
    meta: { current_page: number; last_page: number; total: number };
}

export default function OrdersPage() {
    const { can } = useAuth();
    const [params, setParams] = useSearchParams();
    const [result, setResult] = useState<Page | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState(params.get('search') ?? '');
    const qs = params.toString();

    useEffect(() => {
        let active = true;
        api<Page>(`/admin/orders${qs ? `?${qs}` : ''}`)
            .then((r) => active && (setResult(r), setError(null)))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load orders.'));
        return () => {
            active = false;
        };
    }, [qs]);

    const update = (next: Record<string, string>) => {
        const merged = new URLSearchParams(params);
        Object.entries(next).forEach(([k, v]) => (v ? merged.set(k, v) : merged.delete(k)));
        setParams(merged);
    };

    const onSearch = (e: FormEvent) => {
        e.preventDefault();
        update({ search: query.trim(), page: '' });
    };

    const select = 'min-h-11 rounded-lg border border-line bg-surface px-3 text-sm';

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Orders</h1>
            <div className="flex flex-wrap gap-2">
                <label className="sr-only" htmlFor="status">
                    Status
                </label>
                <select id="status" className={select} value={params.get('status') ?? ''} onChange={(e) => update({ status: e.target.value, page: '' })}>
                    <option value="">All statuses</option>
                    <option value="pending_payment">Awaiting payment</option>
                    <option value="paid">Paid</option>
                    <option value="partially_refunded">Partly refunded</option>
                    <option value="refunded">Refunded</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                {can('attribution.view') && (
                    <>
                        <label className="sr-only" htmlFor="attribution">
                            Attribution
                        </label>
                        <select id="attribution" className={select} value={params.get('attribution') ?? ''} onChange={(e) => update({ attribution: e.target.value, page: '' })}>
                            <option value="">Any attribution</option>
                            <option value="attributed">Attributed</option>
                            <option value="blocked">Blocked</option>
                            <option value="none">No referral</option>
                        </select>
                    </>
                )}
                <form onSubmit={onSearch} className="flex flex-1 gap-2">
                    <label className="sr-only" htmlFor="order-search">
                        Order number or full email
                    </label>
                    <input id="order-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Order number or full email" className="min-h-11 min-w-0 flex-1 rounded-lg border border-line px-3 text-base sm:text-sm" />
                </form>
            </div>
            {error && <Alert tone="error">{error}</Alert>}
            {!result && !error && <Spinner />}
            {result && (
                <Card>
                    {result.data.length === 0 ? (
                        <p className="text-sm text-muted">No orders match.</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {result.data.map((o) => (
                                <li key={o.id}>
                                    <Link to={`/admin/orders/${o.id}`} className="flex flex-wrap items-center justify-between gap-2 py-3 hover:bg-canvas">
                                        <div>
                                            <p className="font-semibold text-ink">
                                                {o.order_number} · {formatMoney(o.total_minor, o.currency)}
                                            </p>
                                            <p className="text-muted">
                                                {formatDateTime(o.placed_at)} · {o.customer_name} · {o.shipping_city}
                                                {o.attribution?.affiliate ? ` · via ${o.attribution.affiliate.display_name}` : ''}
                                            </p>
                                        </div>
                                        <StatusBadge status={o.status} label={o.status_label} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.meta.last_page > 1 && (
                        <div className="mt-4 flex justify-between text-sm">
                            <button type="button" disabled={result.meta.current_page <= 1} onClick={() => update({ page: String(result.meta.current_page - 1) })} className="text-brand-600 disabled:text-muted">
                                Previous
                            </button>
                            <span className="text-muted">
                                Page {result.meta.current_page} of {result.meta.last_page}
                            </span>
                            <button type="button" disabled={result.meta.current_page >= result.meta.last_page} onClick={() => update({ page: String(result.meta.current_page + 1) })} className="text-brand-600 disabled:text-muted">
                                Next
                            </button>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
