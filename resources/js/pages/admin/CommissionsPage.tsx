import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Modal, Select, Spinner, Stat, StatusBadge, TextArea } from '@/components/ui';
import { BUCKET_LABELS, rateLabel, type Buckets, type CommissionRow, type Paginated } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Summary {
    currency: string;
    balances: Buckets;
    on_hold_minor: number;
    auto_release: boolean;
    holding_period_days: number;
}

type Action = 'hold' | 'release-hold' | 'void';

const ACTIONS: Record<Action, { title: string; field: 'reason' | 'note'; button: string; hint: string }> = {
    hold: { title: 'Put commission on hold', field: 'reason', button: 'Hold', hint: 'It will not become available until the hold is released. Not shown to the affiliate.' },
    'release-hold': { title: 'Release hold', field: 'note', button: 'Release hold', hint: 'What you checked. Recorded in the audit log.' },
    void: { title: 'Void commission', field: 'reason', button: 'Void', hint: 'Use when the commission should never have been earned, e.g. confirmed fraud. This cannot be undone.' },
};

export default function CommissionsPage() {
    const { can } = useAuth();
    const [summary, setSummary] = useState<Summary | null>(null);
    const [rows, setRows] = useState<CommissionRow[] | null>(null);
    const [status, setStatus] = useState('');
    const [onHold, setOnHold] = useState('');
    const [order, setOrder] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [action, setAction] = useState<{ type: Action; row: CommissionRow } | null>(null);
    const [text, setText] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(() => {
        const params = new URLSearchParams();
        if (status) params.set('status', status);
        if (onHold) params.set('on_hold', onHold);
        if (order.trim()) params.set('order', order.trim());
        api<Paginated<CommissionRow>>(`/admin/commissions?${params}`)
            .then((r) => setRows(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load commissions.'));
        api<{ data: Summary }>('/admin/commissions/summary').then((r) => setSummary(r.data)).catch(() => undefined);
    }, [status, onHold, order]);

    useEffect(load, [load]);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!action) return;
        reset();
        setSaving(true);
        try {
            await api(`/admin/commissions/${action.row.id}/${action.type}`, { method: 'POST', body: { [ACTIONS[action.type].field]: text } });
            setAction(null);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const release = async (row: CommissionRow) => {
        try {
            await api(`/admin/commissions/${row.id}/release`, { method: 'POST' });
            load();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'Could not release the commission.');
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!summary || !rows) return <Spinner />;
    const b = summary.balances;
    const c = summary.currency;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Commissions</h1>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Pending liability" value={formatMoney(b.pending, c)} hint={`Inside the ${summary.holding_period_days}-day holding period`} />
                <Stat label="Approved" value={formatMoney(b.approved, c)} hint={summary.auto_release ? 'Released automatically' : 'Waiting for finance to release'} />
                <Stat label="Available to affiliates" value={formatMoney(b.available, c)} />
                <Stat label="Reversed" value={formatMoney(b.reversed, c)} hint={summary.on_hold_minor ? `${formatMoney(summary.on_hold_minor, c)} on hold` : undefined} />
            </div>
            <Card>
                <div className="mb-4 grid gap-3 sm:grid-cols-3">
                    <Select label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">All</option>
                        {['pending', 'approved', 'available', 'paid', 'reversed'].map((s) => (
                            <option key={s} value={s}>
                                {BUCKET_LABELS[s]}
                            </option>
                        ))}
                    </Select>
                    <Select label="Hold" value={onHold} onChange={(e) => setOnHold(e.target.value)}>
                        <option value="">Any</option>
                        <option value="1">On hold</option>
                        <option value="0">Not on hold</option>
                    </Select>
                    <div className="space-y-1">
                        <label htmlFor="order-filter" className="block text-sm font-medium text-ink">
                            Order number
                        </label>
                        <input
                            id="order-filter"
                            value={order}
                            onChange={(e) => setOrder(e.target.value)}
                            placeholder="NFC-…"
                            className="block min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-base sm:text-sm"
                        />
                    </div>
                </div>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted">No commissions match.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rows.map((row) => (
                            <li key={row.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink">
                                        {row.affiliate?.display_name} ({row.affiliate?.referral_code}) ·{' '}
                                        {row.order_id && can('orders.view') ? (
                                            <Link className="text-brand-600 hover:underline" to={`/admin/orders/${row.order_id}`}>
                                                {row.order_number}
                                            </Link>
                                        ) : (
                                            row.order_number
                                        )}
                                    </p>
                                    <p className="text-muted">
                                        {row.product_name} × {row.quantity} · {rateLabel(row.kind, row.rate_bp, row.fixed_minor, row.currency)} of {formatMoney(row.base_minor, row.currency)} · rule “
                                        {row.rule?.name}” v{row.rule?.version}
                                        {row.status === 'pending' && ` · eligible ${formatDate(row.eligible_at)}`}
                                    </p>
                                    {row.on_hold && <p className="text-danger">On hold: {row.hold_reason}</p>}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold tabular-nums">{formatMoney(row.net_minor, row.currency)}</span>
                                    {row.reversed_minor > 0 && row.net_minor > 0 && <span className="text-xs text-muted">({formatMoney(row.reversed_minor, row.currency)} reversed)</span>}
                                    <StatusBadge status={row.status} label={row.status_label} />
                                    {can('fraud.review') && ['pending', 'approved'].includes(row.status) && (
                                        <>
                                            <Button variant="secondary" onClick={() => (reset(), setText(''), setAction({ type: row.on_hold ? 'release-hold' : 'hold', row }))}>
                                                {row.on_hold ? 'Release hold' : 'Hold'}
                                            </Button>
                                            <Button variant="secondary" onClick={() => (reset(), setText(''), setAction({ type: 'void', row }))}>
                                                Void
                                            </Button>
                                        </>
                                    )}
                                    {can('commissions.approve') && row.status === 'approved' && !row.on_hold && <Button onClick={() => release(row)}>Release</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Modal title={action ? ACTIONS[action.type].title : ''} open={action !== null} onClose={() => setAction(null)}>
                {action && (
                    <form onSubmit={submit} className="space-y-3">
                        {message && <Alert tone="error">{message}</Alert>}
                        <p className="text-sm text-muted">
                            {action.row.affiliate?.display_name}, {formatMoney(action.row.net_minor, action.row.currency)} on {action.row.order_number}
                        </p>
                        <TextArea
                            label={ACTIONS[action.type].field === 'note' ? 'Note' : 'Reason'}
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            error={fieldErrors.reason ?? fieldErrors.note ?? fieldErrors.status}
                            hint={ACTIONS[action.type].hint}
                        />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setAction(null)}>
                                Close
                            </Button>
                            <Button type="submit" loading={saving}>
                                {ACTIONS[action.type].button}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
