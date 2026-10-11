import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { BarList, LineChart } from '@/components/charts';
import { Alert, Card, Spinner, Stat, StatusBadge } from '@/components/ui';
import { formatDateTime, formatMoney } from '@/lib/format';
import { DefinitionList, formatCount, formatPercent, PeriodPicker, useReportParams } from '@/reporting/controls';
import { DataTable } from '@/reporting/DataTable';
import { FilterBar } from '@/reporting/FilterBar';
import { rangeLabel } from '@/reporting/period';
import type { AffiliateRow, Balances, CampaignRow, Definitions, PayoutQueue, PeriodInfo, ProductRow, SeriesPoint, Totals } from '@/reporting/types';
import { useApiData } from '@/reporting/useApiData';

interface AdminDashboard {
    period: PeriodInfo;
    currency: string;
    affiliates: {
        total: number;
        pending_applications: number;
        active: number;
        suspended: number;
        deactivated: number;
        applied_in_period: number;
        approved_in_period: number;
    };
    totals: Totals;
    series: SeriesPoint[];
    top_affiliates: AffiliateRow[];
    top_products: ProductRow[];
    campaigns: CampaignRow[];
    definitions: Definitions;
    liabilities?: Balances;
    refund_reversals_minor?: number;
    payouts?: PayoutQueue;
    recent_events?: { action: string; actor: string | null; subject_type: string | null; created_at: string | null }[];
}

const FILTERS = ['affiliate_id', 'product_id', 'coupon_id'];

export default function AdminHomePage() {
    const { user, can } = useAuth();

    if (!can('reports.view')) {
        return (
            <div className="space-y-6">
                <h1 className="text-2xl font-semibold text-ink">Staff dashboard</h1>
                <Card title="Signed in as">
                    <p className="text-sm text-ink">
                        {user?.name} · <span className="text-muted">{user?.roles.join(', ')}</span>
                    </p>
                </Card>
                <p className="text-sm text-muted">Use the menu to open the areas your role covers.</p>
            </div>
        );
    }

    return <Dashboard />;
}

function Dashboard() {
    const { can } = useAuth();
    const { range, filters, update, query } = useReportParams(FILTERS);
    const { data, error, loading } = useApiData<AdminDashboard>(`/admin/dashboard?${query}`);

    if (!data && error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const c = data.currency;
    const t = data.totals;
    const a = data.affiliates;
    const filtered = FILTERS.some((k) => filters[k]);
    const dim = loading ? 'opacity-60' : '';

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-ink">Dashboard</h1>
                    <p className="text-sm text-muted">
                        {rangeLabel(data.period.from, data.period.to)}
                        {loading && <span className="ml-2">Updating…</span>}
                    </p>
                </div>
                <PeriodPicker range={range} onChange={(r) => update({ from: r.from, to: r.to })} />
            </div>
            <FilterBar values={filters} onChange={update} />
            {error && <Alert tone="error">{error}</Alert>}

            <section aria-labelledby="affiliates-heading" className={dim}>
                <h2 id="affiliates-heading" className="mb-2 text-sm font-semibold text-muted">
                    Affiliates {filtered && <span className="font-normal">(whole programme, filters do not apply)</span>}
                </h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Active" value={formatCount(a.active)} hint={`${formatCount(a.suspended)} suspended · ${formatCount(a.deactivated)} deactivated`} />
                    <Stat
                        label="Applications waiting"
                        value={
                            <Link to="/admin/affiliates?status=pending" className="hover:underline">
                                {formatCount(a.pending_applications)}
                            </Link>
                        }
                    />
                    <Stat label="Applied in period" value={formatCount(a.applied_in_period)} />
                    <Stat label="Approved in period" value={formatCount(a.approved_in_period)} />
                </div>
            </section>

            <section aria-labelledby="sales-heading" className={dim}>
                <h2 id="sales-heading" className="mb-2 text-sm font-semibold text-muted">
                    Referral sales
                </h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Clicks" value={formatCount(t.clicks)} hint={`${formatCount(t.unique_clicks)} unique`} />
                    <Stat label="Attributed orders" value={formatCount(t.attributed_orders)} hint={`${formatCount(t.paid_orders)} paid in period`} />
                    <Stat label="Conversion" value={formatPercent(t.conversion_rate)} hint="Orders per 100 unique clicks" />
                    <Stat label="Sales" value={formatMoney(t.revenue_minor, c)} hint={`${formatMoney(t.commissionable_minor, c)} commissionable`} />
                    <Stat label="Commission earned" value={formatMoney(t.commission_earned_minor, c)} hint={`${formatMoney(t.commission_reversed_minor, c)} since reversed`} />
                    <Stat label="Refunded" value={formatMoney(t.refunded_minor, c)} hint="On attributed orders" />
                </div>
            </section>

            {(data.liabilities || data.payouts) && (
                <div className={`grid grid-cols-1 gap-4 lg:grid-cols-2 ${dim}`}>
                    {data.liabilities && (
                        <Card title="Commission owed by stage">
                            <p className="-mt-2 mb-3 text-xs text-muted">Right now, from the ledger{filters.affiliate_id ? ', for the chosen affiliate' : ''}.</p>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                                {(
                                    [
                                        ['Pending', data.liabilities.pending],
                                        ['Approved', data.liabilities.approved],
                                        ['Available', data.liabilities.available],
                                        ['In payout', data.liabilities.reserved],
                                        ['Paid, all time', data.liabilities.paid],
                                        ['Refund reversals in period', data.refund_reversals_minor ?? 0],
                                    ] as const
                                ).map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted">{label}</dt>
                                        <dd className={`font-semibold tabular-nums ${value < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(value, c)}</dd>
                                    </div>
                                ))}
                            </dl>
                        </Card>
                    )}
                    {data.payouts && (
                        <Card title="Payouts">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                <div>
                                    <dt className="text-muted">Awaiting review</dt>
                                    <dd className="font-semibold text-ink tabular-nums">
                                        {formatCount(data.payouts.awaiting_review.count)} · {formatMoney(data.payouts.awaiting_review.amount_minor, c)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted">Approved, not yet sent</dt>
                                    <dd className="font-semibold text-ink tabular-nums">
                                        {formatCount(data.payouts.approved_unpaid.count)} · {formatMoney(data.payouts.approved_unpaid.amount_minor, c)}
                                    </dd>
                                </div>
                            </dl>
                            {data.payouts.oldest_requested_at && <p className="mt-3 text-xs text-muted">Oldest request {formatDateTime(data.payouts.oldest_requested_at)}.</p>}
                            <Link to="/admin/payouts" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
                                Review payouts
                            </Link>
                        </Card>
                    )}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <LineChart
                    title="Clicks and orders"
                    data={data.series}
                    series={[
                        { key: 'clicks', label: 'Clicks' },
                        { key: 'unique_clicks', label: 'Unique clicks' },
                        { key: 'orders', label: 'Orders' },
                    ]}
                />
                <LineChart title="Sales" data={data.series} series={[{ key: 'revenue_minor', label: 'Sales' }]} format="money" currency={c} total={formatMoney(t.revenue_minor, c)} />
                <LineChart title="Commission earned" data={data.series} series={[{ key: 'earned_minor', label: 'Commission' }]} format="money" currency={c} total={formatMoney(t.commission_earned_minor, c)} />
                <LineChart
                    title="Conversion"
                    data={data.series}
                    series={[{ key: 'conversion_rate', label: 'Conversion' }]}
                    format="percent"
                    total={formatPercent(t.conversion_rate)}
                    empty="No unique clicks in this period, so there is no conversion rate."
                />
            </div>

            <Card title="Top affiliates">
                <DataTable
                    currency={c}
                    rows={data.top_affiliates as unknown as Record<string, unknown>[]}
                    rowKey={(r) => String(r.affiliate_id)}
                    empty="No referral activity in this period."
                    columns={[
                        {
                            key: 'display_name',
                            label: 'Affiliate',
                            type: 'text',
                            render: (r) =>
                                can('affiliates.view') ? (
                                    <Link to={`/admin/affiliates/${r.affiliate_id}`} className="font-medium text-brand-600 hover:underline">
                                        {String(r.display_name)}
                                    </Link>
                                ) : (
                                    String(r.display_name)
                                ),
                        },
                        { key: 'status', label: 'Status', type: 'text', render: (r) => <StatusBadge status={String(r.status)} /> },
                        { key: 'clicks', label: 'Clicks', type: 'int' },
                        { key: 'orders', label: 'Orders', type: 'int' },
                        { key: 'conversion_rate', label: 'Conversion', type: 'percent' },
                        { key: 'revenue_minor', label: 'Sales', type: 'money' },
                        { key: 'commission_earned_minor', label: 'Commission', type: 'money' },
                    ]}
                />
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <BarList
                    title="Top products"
                    currency={c}
                    items={data.top_products.map((p) => ({
                        key: p.product_id,
                        label: p.name,
                        value: p.revenue_minor,
                        detail: `${formatCount(p.units)} sold · ${formatMoney(p.commission_minor, c)} commission`,
                    }))}
                    empty="No paid referral orders in this period."
                />
                <Card title="Coupon campaigns">
                    <DataTable
                        currency={c}
                        rows={data.campaigns as unknown as Record<string, unknown>[]}
                        rowKey={(r) => String(r.code)}
                        empty="No coupon orders in this period."
                        columns={[
                            { key: 'code', label: 'Code', type: 'text' },
                            { key: 'affiliate', label: 'Affiliate', type: 'text' },
                            { key: 'orders', label: 'Orders', type: 'int' },
                            { key: 'revenue_minor', label: 'Sales', type: 'money' },
                            { key: 'commission_minor', label: 'Commission', type: 'money' },
                        ]}
                    />
                </Card>
            </div>

            {data.recent_events && (
                <Card title="Recent security and audit events">
                    {data.recent_events.length === 0 ? (
                        <p className="text-sm text-muted">No events recorded yet.</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {data.recent_events.map((e, i) => (
                                <li key={i} className="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-3">
                                    <span className="font-mono text-xs text-ink break-all">{e.action}</span>
                                    <span className="text-xs text-muted">
                                        {e.actor ?? 'System'} · {formatDateTime(e.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link to="/admin/audit-logs" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
                        Open the audit log
                    </Link>
                </Card>
            )}

            <p className="text-sm">
                <Link to={`/admin/reports?${query}`} className="font-semibold text-brand-600 hover:underline">
                    Open reports and exports
                </Link>
            </p>

            <DefinitionList definitions={data.definitions} timezone={data.period.timezone} />
        </div>
    );
}
