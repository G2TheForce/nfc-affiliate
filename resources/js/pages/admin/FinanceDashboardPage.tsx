import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { LineChart } from '@/components/charts';
import { Alert, Card, Spinner, Stat, StatusBadge } from '@/components/ui';
import { formatDateTime, formatMoney } from '@/lib/format';
import { DefinitionList, formatCount, PeriodPicker, useReportParams } from '@/reporting/controls';
import { DataTable } from '@/reporting/DataTable';
import { rangeLabel } from '@/reporting/period';
import type { Balances, Definitions, PayoutQueue, PeriodInfo } from '@/reporting/types';
import { useApiData } from '@/reporting/useApiData';
import { PROVIDER_LABELS, type PaymentAttempt } from './orderTypes';

interface FinanceDashboard {
    period: PeriodInfo;
    currency: string;
    balances: Balances;
    on_hold_minor: number;
    flows: Record<string, number>;
    series: { bucket: string; accrued_minor: number; reversed_minor: number; paid_out_minor: number }[];
    payouts: PayoutQueue & { paid_in_period: number };
    refunds: { count: number; amount_minor: number };
    top_owed: { affiliate_id: string; display_name: string; referral_code: string; available_minor: number; reserved_minor: number }[];
    reconciliation: { result: string; finished_at: string | null; issues: number } | null;
    definitions: Definitions;
}

const FLOW_ROWS: [string, string, 1 | -1][] = [
    ['accrued_minor', 'Commission accrued', 1],
    ['adjusted_credit_minor', 'Manual credits', 1],
    ['refund_reversed_minor', 'Reversed by refunds', -1],
    ['voided_minor', 'Voided', -1],
    ['adjusted_debit_minor', 'Manual debits', -1],
    ['released_minor', 'Released to available', 1],
    ['payout_requested_minor', 'Moved into payout requests', 1],
    ['payout_returned_minor', 'Returned from rejected or cancelled payouts', 1],
    ['paid_out_minor', 'Paid out', 1],
];

/** Online payments the system could not settle by itself (amount mismatch, double payment, provider refund, stuck pending). */
function PaymentsToReview() {
    const { data } = useApiData<PaymentAttempt[]>('/admin/payment-attempts?filter=review');
    if (!data || data.length === 0) return null;
    return (
        <Alert tone="error">
            <p className="font-semibold">
                {data.length === 30 ? 'At least 30' : data.length} online payment{data.length === 1 ? ' needs' : 's need'} a look
            </p>
            <ul className="mt-1 space-y-1">
                {data.slice(0, 5).map((a) => (
                    <li key={a.id}>
                        <Link to={`/admin/orders/${a.order?.id}`} className="font-semibold underline">
                            {a.order?.order_number}
                        </Link>{' '}
                        ({PROVIDER_LABELS[a.provider] ?? a.provider}, {formatMoney(a.amount_minor, a.currency)}): {a.review_reason}
                    </li>
                ))}
            </ul>
        </Alert>
    );
}

export default function FinanceDashboardPage() {
    const { can } = useAuth();
    const { range, update, query } = useReportParams();
    const { data, error, loading } = useApiData<FinanceDashboard>(`/admin/finance/dashboard?${query}`);

    if (!data && error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const c = data.currency;
    const b = data.balances;
    const owed = b.pending + b.approved + b.available + b.reserved;

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-ink">Finance</h1>
                    <p className="text-sm text-muted">
                        Flows for {rangeLabel(data.period.from, data.period.to)}
                        {loading && <span className="ml-2">Updating…</span>}
                    </p>
                </div>
                <PeriodPicker range={range} onChange={(r) => update({ from: r.from, to: r.to })} />
            </div>
            {error && <Alert tone="error">{error}</Alert>}

            {data.reconciliation && data.reconciliation.result !== 'clean' && (
                <Alert tone="error">
                    The last reconciliation check found {data.reconciliation.issues} issue{data.reconciliation.issues === 1 ? '' : 's'}.{' '}
                    <Link to="/admin/reconciliation" className="font-semibold underline">
                        Review it
                    </Link>{' '}
                    before paying anyone.
                </Alert>
            )}

            {can('payments.confirm') && can('orders.view') && <PaymentsToReview />}

            <section aria-labelledby="owed-heading">
                <h2 id="owed-heading" className="mb-2 text-sm font-semibold text-muted">
                    Owed to affiliates right now
                </h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Total owed" value={formatMoney(owed, c)} hint="Pending, approved, available and in payout" />
                    <Stat label="Available to pay" value={formatMoney(b.available, c)} />
                    <Stat label="In payout requests" value={formatMoney(b.reserved, c)} />
                    <Stat label="Still in holding period" value={formatMoney(b.pending + b.approved, c)} hint={`${formatMoney(data.on_hold_minor, c)} on fraud hold`} />
                    <Stat label="Paid, all time" value={formatMoney(b.paid, c)} />
                    <Stat label="Reversed, all time" value={formatMoney(b.reversed, c)} />
                    <Stat label="Refunds in period" value={formatMoney(data.refunds.amount_minor, c)} hint={`${formatCount(data.refunds.count)} refunds, all orders`} />
                    <Stat label="Payouts sent in period" value={formatCount(data.payouts.paid_in_period)} />
                </div>
            </section>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Payout queue">
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
                    {can('payouts.view') && (
                        <Link to="/admin/payouts" className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">
                            Open payouts
                        </Link>
                    )}
                </Card>
                <Card title="Reconciliation">
                    {data.reconciliation ? (
                        <div className="space-y-2 text-sm">
                            <p>
                                <StatusBadge status={data.reconciliation.result} label={data.reconciliation.result === 'clean' ? 'Clean' : 'Drift found'} />
                            </p>
                            <p className="text-muted">Last checked {formatDateTime(data.reconciliation.finished_at)}.</p>
                            <Link to="/admin/reconciliation" className="inline-block font-semibold text-brand-600 hover:underline">
                                Open reconciliation
                            </Link>
                        </div>
                    ) : (
                        <p className="text-sm text-muted">{can('reconciliation.view') ? 'No check has run yet.' : 'Your role does not include reconciliation.'}</p>
                    )}
                </Card>
            </div>

            <LineChart
                title="Commission ledger"
                data={data.series}
                series={[
                    { key: 'accrued_minor', label: 'Accrued' },
                    { key: 'reversed_minor', label: 'Reversed' },
                    { key: 'paid_out_minor', label: 'Paid out' },
                ]}
                format="money"
                currency={c}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Ledger movements in period">
                    <table className="w-full text-sm">
                        <tbody>
                            {FLOW_ROWS.map(([key, label, sign]) => (
                                <tr key={key} className="border-b border-line last:border-0">
                                    <td className="py-2 pr-2 text-ink">{label}</td>
                                    <td className="py-2 text-right whitespace-nowrap tabular-nums">
                                        {sign < 0 && data.flows[key] ? '−' : ''}
                                        {formatMoney(data.flows[key] ?? 0, c)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
                <Card title="Largest balances owed">
                    <DataTable
                        currency={c}
                        rows={data.top_owed as unknown as Record<string, unknown>[]}
                        rowKey={(r) => String(r.affiliate_id)}
                        empty="Nothing is owed right now."
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
                            { key: 'available_minor', label: 'Available', type: 'money' },
                            { key: 'reserved_minor', label: 'In payout', type: 'money' },
                        ]}
                    />
                </Card>
            </div>

            <p className="text-sm">
                <Link to={`/admin/reports?${query}`} className="font-semibold text-brand-600 hover:underline">
                    Open reports and exports
                </Link>
            </p>

            <DefinitionList definitions={data.definitions} timezone={data.period.timezone} />
        </div>
    );
}
