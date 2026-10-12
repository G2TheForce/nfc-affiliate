import { useEffect, useState } from 'react';
import { DailyBars } from '@/components/DailyBars';
import { Alert, Card, Spinner, Stat, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

interface Summary {
    from: string;
    to: string;
    currency: string;
    totals: { clicks: number; unique_clicks: number; orders: number; cancelled_orders: number; order_value_minor: number; conversion_rate: number | null };
    daily: { date: string; clicks: number; orders: number }[];
    by_link: { link_id: string | null; name: string; clicks: number; unique_clicks: number; orders: number }[];
}

interface ReferralOrder {
    reference: string;
    placed_at: string;
    status: string;
    status_label: string;
    currency: string;
    order_value_minor: number;
    source_label: string;
    items: { name: string; quantity: number }[];
}

const RANGES = [7, 30, 90];

export default function ReferralStatsPage() {
    const [days, setDays] = useState(30);
    const [summary, setSummary] = useState<Summary | null>(null);
    const [orders, setOrders] = useState<ReferralOrder[]>([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        const to = new Date();
        const from = new Date(to.getTime() - (days - 1) * 86400000);
        const ymd = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Kathmandu' });
        api<{ data: Summary }>(`/affiliate/referrals/summary?from=${ymd(from)}&to=${ymd(to)}`)
            .then((r) => active && (setSummary(r.data), setError(null)))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load your referral report.'));
        return () => {
            active = false;
        };
    }, [days]);

    useEffect(() => {
        api<{ data: ReferralOrder[] }>('/affiliate/referrals/orders').then((r) => setOrders(r.data)).catch(() => undefined);
    }, []);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!summary) return <Spinner />;
    const t = summary.totals;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Referral performance</h1>
                <div className="flex gap-1" role="group" aria-label="Date range">
                    {RANGES.map((r) => (
                        <button
                            key={r}
                            type="button"
                            aria-pressed={days === r}
                            onClick={() => setDays(r)}
                            className={`min-h-9 rounded-full border px-3 text-sm font-medium ${days === r ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-line bg-surface text-muted'}`}
                        >
                            {r} days
                        </button>
                    ))}
                </div>
            </div>
            <p className="text-sm text-muted">
                {formatDate(summary.from)} to {formatDate(summary.to)} (Nepal time). Placed orders are not yet sales: commission is earned only on paid orders after
                the holding period.
            </p>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Clicks" value={t.clicks} hint={`${t.unique_clicks} unique`} />
                <Stat label="Orders placed" value={t.orders} hint={t.cancelled_orders ? `${t.cancelled_orders} cancelled` : undefined} />
                <Stat label="Conversion" value={t.conversion_rate === null ? '—' : `${t.conversion_rate}%`} hint="Orders per 100 unique clicks" />
                <Stat label="Order value" value={formatMoney(t.order_value_minor, summary.currency)} hint="After discounts, before delivery" />
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <DailyBars title="Clicks" data={summary.daily} valueKey="clicks" />
                <DailyBars title="Orders" data={summary.daily} valueKey="orders" />
            </div>
            <Card title="By link">
                {summary.by_link.length === 0 ? (
                    <p className="text-sm text-muted">No clicks in this period.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th className="py-2 font-medium">Link</th>
                                    <th className="py-2 text-right font-medium">Clicks</th>
                                    <th className="py-2 text-right font-medium">Unique</th>
                                    <th className="py-2 text-right font-medium">Orders</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {summary.by_link.map((l) => (
                                    <tr key={l.link_id ?? 'main'}>
                                        <td className="py-2">{l.name}</td>
                                        <td className="py-2 text-right tabular-nums">{l.clicks}</td>
                                        <td className="py-2 text-right tabular-nums">{l.unique_clicks}</td>
                                        <td className="py-2 text-right tabular-nums">{l.orders}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <Card title="Recent referred orders">
                {orders.length === 0 ? (
                    <p className="text-sm text-muted">No referred orders yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {orders.map((o) => (
                            <li key={o.reference + o.placed_at} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                <div>
                                    <p className="font-medium text-ink">
                                        Order {o.reference} · {formatMoney(o.order_value_minor, o.currency)}
                                    </p>
                                    <p className="text-muted">
                                        {formatDate(o.placed_at)} · {o.source_label} · {o.items.map((i) => `${i.name} × ${i.quantity}`).join(', ')}
                                    </p>
                                </div>
                                <StatusBadge status={o.status} label={o.status_label} />
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
