import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Card, Spinner, Stat } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

interface Programme {
    from: string;
    to: string;
    currency: string;
    totals: { clicks: number; unique_clicks: number; orders: number; cancelled_orders: number; order_value_minor: number; conversion_rate: number | null };
    top_affiliates: { affiliate_id: string; display_name: string; referral_code: string | null; clicks: number; unique_clicks: number; orders: number; order_value_minor: number }[];
}

export default function ReferralsPage() {
    const { can } = useAuth();
    const [data, setData] = useState<Programme | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: Programme }>('/admin/referrals/summary')
            .then((r) => setData(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load the referral report.'));
    }, []);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;
    const t = data.totals;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Referral report</h1>
            <p className="text-sm text-muted">
                Last 30 days, {formatDate(data.from)} to {formatDate(data.to)}. Placed orders, not paid sales.
            </p>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Clicks" value={t.clicks} hint={`${t.unique_clicks} unique`} />
                <Stat label="Referred orders" value={t.orders} hint={t.cancelled_orders ? `${t.cancelled_orders} cancelled` : undefined} />
                <Stat label="Conversion" value={t.conversion_rate === null ? '—' : `${t.conversion_rate}%`} />
                <Stat label="Order value" value={formatMoney(t.order_value_minor, data.currency)} />
            </div>
            <Card title="Top affiliates">
                {data.top_affiliates.length === 0 ? (
                    <p className="text-sm text-muted">No referral activity in this period.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th className="py-2 font-medium">Affiliate</th>
                                    <th className="py-2 text-right font-medium">Clicks</th>
                                    <th className="py-2 text-right font-medium">Orders</th>
                                    <th className="py-2 text-right font-medium">Order value</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {data.top_affiliates.map((r) => (
                                    <tr key={r.affiliate_id}>
                                        <td className="py-2">
                                            {can('affiliates.view') ? (
                                                <Link className="text-brand-600 hover:underline" to={`/admin/affiliates/${r.affiliate_id}`}>
                                                    {r.display_name}
                                                </Link>
                                            ) : (
                                                r.display_name
                                            )}
                                            <span className="block text-xs text-muted">{r.referral_code}</span>
                                        </td>
                                        <td className="py-2 text-right tabular-nums">{r.clicks}</td>
                                        <td className="py-2 text-right tabular-nums">{r.orders}</td>
                                        <td className="py-2 text-right tabular-nums">{formatMoney(r.order_value_minor, data.currency)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </div>
    );
}
