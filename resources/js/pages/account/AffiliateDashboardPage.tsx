import { Link } from 'react-router';
import { BarList, LineChart } from '@/components/charts';
import { Alert, Card, Spinner, Stat } from '@/components/ui';
import { formatMoney } from '@/lib/format';
import { DefinitionList, ExportButtons, formatCount, formatPercent, PeriodPicker, useReportParams } from '@/reporting/controls';
import { DataTable } from '@/reporting/DataTable';
import { rangeLabel } from '@/reporting/period';
import type { Balances, CampaignRow, Definitions, LinkRow, PeriodInfo, ProductRow, ReportInfo, SeriesPoint, Totals } from '@/reporting/types';
import { useApiData } from '@/reporting/useApiData';

interface Dashboard {
    period: PeriodInfo;
    currency: string;
    totals: Totals;
    balances: Balances;
    series: SeriesPoint[];
    products: ProductRow[];
    campaigns: CampaignRow[];
    links: LinkRow[];
    referral_code: string;
    definitions: Definitions;
}

export default function AffiliateDashboardPage() {
    const { range, update, query } = useReportParams();
    const { data, error, loading } = useApiData<Dashboard>(`/affiliate/dashboard?${query}`);
    const reports = useApiData<{ reports: ReportInfo[] }>('/affiliate/reports');

    if (!data && error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const c = data.currency;
    const t = data.totals;
    const b = data.balances;

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-ink">Affiliate dashboard</h1>
                    <p className="text-sm text-muted">
                        {rangeLabel(data.period.from, data.period.to)}
                        {loading && <span className="ml-2">Updating…</span>}
                    </p>
                </div>
                <PeriodPicker range={range} onChange={(r) => update({ from: r.from, to: r.to })} />
            </div>
            {error && <Alert tone="error">{error}</Alert>}

            <section aria-label="Traffic and orders" className={`grid grid-cols-2 gap-3 lg:grid-cols-4 ${loading ? 'opacity-60' : ''}`}>
                <Stat label="Clicks" value={formatCount(t.clicks)} />
                <Stat label="Unique clicks" value={formatCount(t.unique_clicks)} />
                <Stat label="Attributed orders" value={formatCount(t.attributed_orders)} hint={`${formatCount(t.paid_orders)} paid in this period`} />
                <Stat label="Conversion" value={formatPercent(t.conversion_rate)} hint="Orders per 100 unique clicks" />
            </section>

            <section aria-label="Sales and commission" className={`grid grid-cols-2 gap-3 lg:grid-cols-4 ${loading ? 'opacity-60' : ''}`}>
                <Stat label="Sales" value={formatMoney(t.revenue_minor, c)} hint="Paid orders, before refunds" />
                <Stat label="Commissionable sales" value={formatMoney(t.commissionable_minor, c)} />
                <Stat label="Commission earned" value={formatMoney(t.commission_earned_minor, c)} hint={t.commission_reversed_minor ? `${formatMoney(t.commission_reversed_minor, c)} since reversed` : undefined} />
                <Stat label="Refunded" value={formatMoney(t.refunded_minor, c)} />
            </section>

            <Card title="Your balance">
                <p className="-mt-2 mb-3 text-xs text-muted">Right now, not limited to the period above.</p>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    {(
                        [
                            ['Pending', b.pending],
                            ['Approved', b.approved],
                            ['Available', b.available],
                            ['In payout', b.reserved],
                            ['Paid', b.paid],
                            ['Reversed', b.reversed],
                        ] as const
                    ).map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-muted">{label}</dt>
                            <dd className={`font-semibold tabular-nums ${value < 0 ? 'text-danger' : 'text-ink'}`}>{formatMoney(value, c)}</dd>
                        </div>
                    ))}
                </dl>
                <Link to="/account/payouts" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
                    Go to payouts
                </Link>
            </Card>

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
                <LineChart title="Commission earned" data={data.series} series={[{ key: 'earned_minor', label: 'Commission' }]} format="money" currency={c} total={formatMoney(t.commission_earned_minor, c)} />
                <LineChart title="Sales" data={data.series} series={[{ key: 'revenue_minor', label: 'Sales' }]} format="money" currency={c} total={formatMoney(t.revenue_minor, c)} />
                <LineChart
                    title="Conversion"
                    data={data.series}
                    series={[{ key: 'conversion_rate', label: 'Conversion' }]}
                    format="percent"
                    total={formatPercent(t.conversion_rate)}
                    empty="No unique clicks in this period, so there is no conversion rate."
                />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <BarList
                    title="Products sold"
                    currency={c}
                    items={data.products.map((p) => ({
                        key: p.product_id,
                        label: p.name,
                        value: p.revenue_minor,
                        detail: `${formatCount(p.units)} sold · ${formatMoney(p.commission_minor, c)} commission`,
                    }))}
                    empty="No paid orders from your referrals in this period."
                />
                <Card title="Coupon codes">
                    <DataTable
                        currency={c}
                        rows={data.campaigns as unknown as Record<string, unknown>[]}
                        rowKey={(r) => String(r.code)}
                        empty="None of your coupon codes were used in this period."
                        columns={[
                            { key: 'code', label: 'Code', type: 'text' },
                            { key: 'orders', label: 'Orders', type: 'int' },
                            { key: 'revenue_minor', label: 'Sales', type: 'money' },
                            { key: 'commission_minor', label: 'Commission', type: 'money' },
                        ]}
                    />
                </Card>
            </div>

            <Card title="Referral links">
                <DataTable
                    rows={data.links as unknown as Record<string, unknown>[]}
                    rowKey={(r, i) => String(r.link_id ?? `main-${i}`)}
                    empty="No clicks on your links in this period."
                    columns={[
                        { key: 'name', label: 'Link', type: 'text' },
                        { key: 'clicks', label: 'Clicks', type: 'int' },
                        { key: 'unique_clicks', label: 'Unique', type: 'int' },
                        { key: 'orders', label: 'Orders', type: 'int' },
                    ]}
                />
                <Link to="/account/affiliate/links" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
                    Manage links
                </Link>
            </Card>

            <Card title="Download your records">
                {reports.error && <Alert tone="error">{reports.error}</Alert>}
                {!reports.data ? (
                    !reports.error && <p className="text-sm text-muted">Loading…</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {reports.data.reports.map((r) => (
                            <li key={r.key} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0 text-sm">
                                    <p className="font-medium text-ink">{r.title}</p>
                                    <p className="text-muted">
                                        {r.description}
                                        {r.uses_period && ' Uses the period above.'}
                                    </p>
                                </div>
                                <ExportButtons path={`/affiliate/reports/${r.key}/export`} query={r.uses_period ? query : ''} label={r.title} />
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <DefinitionList definitions={data.definitions} timezone={data.period.timezone} />
        </div>
    );
}
