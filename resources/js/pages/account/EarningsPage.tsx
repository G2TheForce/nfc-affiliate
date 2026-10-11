import { useEffect, useState } from 'react';
import { Alert, Card, Spinner, Stat, StatusBadge } from '@/components/ui';
import { BUCKET_LABELS, movement, rateLabel, type Buckets, type CommissionRow, type LedgerEntry, type Paginated } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';

interface Summary {
    currency: string;
    balances: Buckets;
    counts: Record<string, number>;
    on_hold_count: number;
    holding_period_days: number;
    next_eligible_at: string | null;
}

interface Rates {
    default: { kind: string; rate_bp: number | null; fixed_minor: number | null; currency: string | null } | null;
    products: { product_id: string; name: string; price_minor: number; currency: string; earns: boolean; kind: string | null; rate_bp: number | null; fixed_minor: number | null; commission_per_unit_minor: number }[];
    notes: string[];
}

const FILTERS = ['', 'pending', 'approved', 'available', 'paid', 'reversed'];

export default function EarningsPage() {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [status, setStatus] = useState('');
    const [commissions, setCommissions] = useState<CommissionRow[] | null>(null);
    const [ledger, setLedger] = useState<LedgerEntry[]>([]);
    const [rates, setRates] = useState<Rates | null>(null);
    const [tab, setTab] = useState<'commissions' | 'ledger' | 'rates'>('commissions');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: Summary }>('/affiliate/earnings/summary')
            .then((r) => setSummary(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load your earnings.'));
        api<Paginated<LedgerEntry>>('/affiliate/earnings/ledger').then((r) => setLedger(r.data)).catch(() => undefined);
        // Rates are only shown to active members; a suspended member gets 403 and the tab stays empty.
        api<{ data: Rates }>('/affiliate/earnings/rates').then((r) => setRates(r.data)).catch(() => undefined);
    }, []);

    useEffect(() => {
        let active = true;
        api<Paginated<CommissionRow>>(`/affiliate/earnings/commissions${status ? `?status=${status}` : ''}`)
            .then((r) => active && setCommissions(r.data))
            .catch(() => active && setCommissions([]));
        return () => {
            active = false;
        };
    }, [status]);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!summary) return <Spinner />;
    const b = summary.balances;
    const c = summary.currency;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Earnings</h1>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Available" value={formatMoney(b.available, c)} hint="Ready for a payout request" />
                <Stat
                    label="Pending"
                    value={formatMoney(b.pending + b.approved, c)}
                    hint={summary.next_eligible_at ? `Next available ${formatDate(summary.next_eligible_at)}` : `Held for ${summary.holding_period_days} days after payment`}
                />
                <Stat label="Paid out" value={formatMoney(b.paid, c)} />
                <Stat label="Reversed" value={formatMoney(b.reversed, c)} hint="Refunded or cancelled sales" />
            </div>
            {b.available < 0 && (
                <Alert tone="info">
                    Your available balance is below zero because a sale was refunded after its commission was paid. Future commission will make up the difference first.
                </Alert>
            )}
            {summary.on_hold_count > 0 && (
                <Alert tone="info">
                    {summary.on_hold_count} commission{summary.on_hold_count > 1 ? 's are' : ' is'} on hold for review. Our team will contact you if anything is needed.
                </Alert>
            )}

            <div className="flex gap-1 border-b border-line" role="tablist">
                {(['commissions', 'ledger', 'rates'] as const).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={`min-h-11 border-b-2 px-3 text-sm font-medium ${tab === t ? 'border-brand-600 text-brand-700' : 'border-transparent text-muted'}`}
                    >
                        {{ commissions: 'Commissions', ledger: 'Statement', rates: 'My rates' }[t]}
                    </button>
                ))}
            </div>

            {tab === 'commissions' && (
                <Card>
                    <div className="mb-3 flex flex-wrap gap-1" role="group" aria-label="Filter by status">
                        {FILTERS.map((f) => (
                            <button
                                key={f || 'all'}
                                type="button"
                                aria-pressed={status === f}
                                onClick={() => setStatus(f)}
                                className={`min-h-9 rounded-full border px-3 text-sm font-medium ${status === f ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-line bg-surface text-muted'}`}
                            >
                                {f ? BUCKET_LABELS[f] : 'All'}
                                {f && summary.counts[f] ? ` (${summary.counts[f]})` : ''}
                            </button>
                        ))}
                    </div>
                    {commissions === null ? (
                        <p className="text-sm text-muted">Loading…</p>
                    ) : commissions.length === 0 ? (
                        <p className="text-sm text-muted">No commissions yet. Commission appears here when a referred order is paid.</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {commissions.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-start justify-between gap-2 py-3">
                                    <div className="min-w-0">
                                        <p className="font-medium text-ink">
                                            {row.product_name} × {row.quantity} · order {row.order_number}
                                        </p>
                                        <p className="text-muted">
                                            {rateLabel(row.kind, row.rate_bp, row.fixed_minor, row.currency)} of {formatMoney(row.base_minor, row.currency)}
                                            {row.status === 'pending' && ` · available from ${formatDate(row.eligible_at)}`}
                                            {row.reversed_minor > 0 && ` · ${formatMoney(row.reversed_minor, row.currency)} reversed after a refund`}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold tabular-nums">{formatMoney(row.net_minor, row.currency)}</span>
                                        {row.on_hold ? <StatusBadge status="on_hold" label="On hold" /> : <StatusBadge status={row.status} label={row.status_label} />}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {tab === 'ledger' && (
                <Card>
                    {ledger.length === 0 ? (
                        <p className="text-sm text-muted">No entries yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-muted">
                                    <tr>
                                        <th className="py-2 font-medium">When</th>
                                        <th className="py-2 font-medium">What</th>
                                        <th className="py-2 font-medium">Movement</th>
                                        <th className="py-2 text-right font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {ledger.map((e, i) => (
                                        <tr key={i}>
                                            <td className="py-2 whitespace-nowrap">{formatDateTime(e.created_at)}</td>
                                            <td className="py-2">{e.label}</td>
                                            <td className="py-2 text-muted">{movement(e)}</td>
                                            <td className="py-2 text-right tabular-nums">{formatMoney(e.amount_minor, e.currency)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            )}

            {tab === 'rates' && (
                <Card>
                    {!rates ? (
                        <p className="text-sm text-muted">Rates are shown while your membership is active.</p>
                    ) : (
                        <div className="space-y-4">
                            <p className="text-sm">
                                Your default rate:{' '}
                                <strong>{rates.default ? rateLabel(rates.default.kind, rates.default.rate_bp, rates.default.fixed_minor, rates.default.currency ?? c) : 'not set yet'}</strong>
                            </p>
                            <ul className="divide-y divide-line text-sm">
                                {rates.products.map((p) => (
                                    <li key={p.product_id} className="flex flex-wrap justify-between gap-2 py-2">
                                        <span>
                                            {p.name} <span className="text-muted">({formatMoney(p.price_minor, p.currency)})</span>
                                        </span>
                                        <span className="tabular-nums">
                                            {p.earns && p.kind ? `${rateLabel(p.kind, p.rate_bp, p.fixed_minor, p.currency)} · ${formatMoney(p.commission_per_unit_minor, p.currency)} each` : 'No commission'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <ul className="list-disc space-y-1 pl-5 text-sm text-muted">
                                {rates.notes.map((n) => (
                                    <li key={n}>{n}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
