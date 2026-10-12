import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { Alert, Button, Card, Field, Modal, Spinner, Stat, StatusBadge, TextArea } from '@/components/ui';
import { movement, type LedgerEntry, type Paginated } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import type { StaffPayout } from '@/payouts/types';

interface Summary {
    currency: string;
    by_status: Record<string, { count: number; amount_minor: number }>;
    minimum_minor: number;
    require_separate_payer: boolean;
}

interface Detail extends StaffPayout {
    destination_details: Record<string, string | null> | null;
    open_fraud_flags: { id: string; signal_label: string; severity: string; detected_at: string }[];
    commissions: { id: string; order_number: string | null; product: string | null; amount_minor: number; status: string }[];
    ledger: LedgerEntry[];
}

type Action = 'approve' | 'reject' | 'mark-paid' | 'mark-failed';

const FILTERS = [
    { value: '', label: 'All' },
    { value: 'requested', label: 'Requested' },
    { value: 'approved', label: 'Approved' },
    { value: 'paid', label: 'Paid' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'failed', label: 'Failed' },
    { value: 'cancelled', label: 'Cancelled' },
];

const ACTION_TITLES: Record<Action, string> = {
    approve: 'Approve payout',
    reject: 'Reject payout',
    'mark-paid': 'Confirm transfer',
    'mark-failed': 'Record failed transfer',
};

const DETAIL_LABELS: Record<string, string> = {
    type_label: 'Method',
    account_name: 'Account holder',
    bank_name: 'Bank',
    branch: 'Branch',
    account_number: 'Account number',
    wallet_id: 'Wallet number',
};

export default function PayoutsPage() {
    const [params, setParams] = useSearchParams();
    const [status, setStatus] = useState('');
    const [rows, setRows] = useState<StaffPayout[] | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [action, setAction] = useState<Action | null>(null);
    const [text, setText] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const openId = params.get('payout');

    const load = useCallback(() => {
        api<Paginated<StaffPayout>>(`/admin/payouts${status ? `?status=${status}` : ''}`)
            .then((r) => setRows(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load payouts.'));
        api<{ data: Summary }>('/admin/payouts/summary')
            .then((r) => setSummary(r.data))
            .catch(() => undefined);
    }, [status]);

    useEffect(load, [load]);

    useEffect(() => {
        if (!openId) return;
        let active = true;
        api<{ data: Detail }>(`/admin/payouts/${openId}`)
            .then((r) => active && setDetail(r.data))
            .catch(() => active && setParams({}));
        return () => {
            active = false;
        };
    }, [openId, setParams]);

    const open = (id: string) => setParams({ payout: id });
    const close = () => {
        setDetail(null);
        setParams({});
    };

    const startAction = (a: Action) => {
        reset();
        setText('');
        setAction(a);
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!detail || !action) return;
        reset();
        setSaving(true);
        const body =
            action === 'mark-paid' ? { reference: text.trim() } : action === 'mark-failed' ? { reason: text } : { note: text || null };
        try {
            await api(`/admin/payouts/${detail.id}/${action}`, { method: 'POST', body });
            setAction(null);
            const fresh = await api<{ data: Detail }>(`/admin/payouts/${detail.id}`);
            setDetail(fresh.data);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!rows) return <Spinner />;
    const c = summary?.currency ?? 'NPR';
    const s = summary?.by_status;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Payouts</h1>
            {s && (
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Waiting for review" value={s.requested?.count ?? 0} hint={formatMoney(s.requested?.amount_minor ?? 0, c)} />
                    <Stat label="Approved, not sent" value={s.approved?.count ?? 0} hint={formatMoney(s.approved?.amount_minor ?? 0, c)} />
                    <Stat label="Paid out" value={formatMoney(s.paid?.amount_minor ?? 0, c)} hint={`${s.paid?.count ?? 0} payouts`} />
                    <Stat label="Failed or rejected" value={(s.failed?.count ?? 0) + (s.rejected?.count ?? 0)} />
                </div>
            )}
            <p className="text-sm text-muted">
                No payout provider is connected. Make the transfer from the company bank or wallet, then confirm it here with the transfer reference. Rejected and failed payouts return the money to the affiliate's available balance.
            </p>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                {FILTERS.map((f) => (
                    <Button key={f.value} variant={status === f.value ? 'primary' : 'secondary'} aria-pressed={status === f.value} onClick={() => setStatus(f.value)}>
                        {f.label}
                    </Button>
                ))}
            </div>
            <Card>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted">No payouts.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <button type="button" onClick={() => open(r.id)} className="flex w-full flex-col gap-1 py-3 text-left hover:bg-canvas sm:flex-row sm:items-center sm:justify-between">
                                    <span className="min-w-0">
                                        <span className="block font-medium text-ink">
                                            {r.affiliate.display_name} ({r.affiliate.referral_code}) · <span className="tabular-nums">{formatMoney(r.amount_minor, r.currency)}</span>
                                        </span>
                                        <span className="block text-xs text-muted">
                                            {r.method_label} {r.destination} · requested {formatDateTime(r.requested_at)}
                                            {r.payment_reference && ` · ref ${r.payment_reference}`}
                                        </span>
                                    </span>
                                    <StatusBadge status={r.status} label={r.status_label} />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal title="Payout" open={detail !== null && action === null} onClose={close}>
                {detail && (
                    <div className="max-h-[75vh] space-y-4 overflow-y-auto text-sm">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-lg font-semibold text-ink tabular-nums">{formatMoney(detail.amount_minor, detail.currency)}</p>
                            <StatusBadge status={detail.status} label={detail.status_label} />
                        </div>
                        <p>
                            {detail.affiliate.display_name} ({detail.affiliate.referral_code})
                            {detail.affiliate.status !== 'approved' && <span className="text-danger"> · membership {detail.affiliate.status}</span>}
                        </p>
                        {detail.open_fraud_flags.length > 0 && (
                            <Alert tone="error">
                                Open fraud flags on this affiliate: {detail.open_fraud_flags.map((f) => f.signal_label).join('; ')}. Check them before approving or paying.
                            </Alert>
                        )}
                        {detail.destination_details ? (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border border-line p-3">
                                {Object.entries(DETAIL_LABELS).map(([key, label]) =>
                                    detail.destination_details?.[key] ? (
                                        <div key={key} className="contents">
                                            <dt className="text-muted">{label}</dt>
                                            <dd className="break-all font-medium text-ink">{detail.destination_details[key]}</dd>
                                        </div>
                                    ) : null,
                                )}
                            </dl>
                        ) : (
                            <p className="text-muted">
                                {detail.method_label}: {detail.destination}, {detail.account_name}
                            </p>
                        )}
                        <p className="text-xs text-muted">
                            Requested {formatDateTime(detail.requested_at)}
                            {detail.reviewed_by && ` · reviewed by ${detail.reviewed_by} ${formatDateTime(detail.reviewed_at)}`}
                            {detail.paid_by && ` · paid by ${detail.paid_by} ${formatDateTime(detail.paid_at)}`}
                        </p>
                        {detail.payment_reference && <p>Transfer reference: <span className="font-medium">{detail.payment_reference}</span></p>}
                        {detail.review_note && <p>Review note: {detail.review_note}</p>}
                        {detail.failure_reason && <p>Failure: {detail.failure_reason}</p>}

                        <div>
                            <h3 className="font-semibold text-ink">Commissions covered ({detail.commissions.length})</h3>
                            <ul className="mt-1 divide-y divide-line">
                                {detail.commissions.map((cm) => (
                                    <li key={cm.id} className="flex justify-between gap-2 py-1.5">
                                        <span className="min-w-0 truncate">
                                            {cm.order_number} · {cm.product}
                                        </span>
                                        <span className="shrink-0 tabular-nums">
                                            {formatMoney(cm.amount_minor, detail.currency)} <StatusBadge status={cm.status} />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-1 text-xs text-muted">The amount is the whole available balance, so it also includes approved adjustments and earlier refund clawbacks.</p>
                        </div>
                        <div>
                            <h3 className="font-semibold text-ink">Ledger</h3>
                            <ul className="mt-1 space-y-1">
                                {detail.ledger.map((l, i) => (
                                    <li key={i} className="flex justify-between gap-2">
                                        <span>{movement(l)}</span>
                                        <span className="tabular-nums text-muted">
                                            {formatMoney(l.amount_minor, l.currency)} · {formatDateTime(l.created_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="flex flex-wrap justify-end gap-2">
                            <Button variant="secondary" onClick={close}>
                                Close
                            </Button>
                            {detail.can_reject && (
                                <Button variant="secondary" onClick={() => startAction('reject')}>
                                    Reject
                                </Button>
                            )}
                            {detail.can_mark_failed && (
                                <Button variant="secondary" onClick={() => startAction('mark-failed')}>
                                    Transfer failed
                                </Button>
                            )}
                            {detail.can_review && <Button onClick={() => startAction('approve')}>Approve</Button>}
                            {detail.can_mark_paid && <Button onClick={() => startAction('mark-paid')}>Confirm transfer</Button>}
                        </div>
                        {detail.status === 'approved' && !detail.can_mark_paid && summary?.require_separate_payer && (
                            <p className="text-xs text-muted">Another finance person must confirm the transfer, because you approved it.</p>
                        )}
                    </div>
                )}
            </Modal>

            <Modal title={action ? ACTION_TITLES[action] : ''} open={action !== null} onClose={() => setAction(null)}>
                {detail && action && (
                    <form onSubmit={submit} className="space-y-3 text-sm">
                        {message && <Alert tone="error">{message}</Alert>}
                        {fieldErrors.payout && <Alert tone="error">{fieldErrors.payout}</Alert>}
                        <p>
                            {formatMoney(detail.amount_minor, detail.currency)} to {detail.affiliate.display_name}, {detail.method_label} {detail.destination}.
                        </p>
                        {action === 'mark-paid' ? (
                            <Field
                                label="Transfer reference"
                                autoComplete="off"
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                error={fieldErrors.reference}
                                hint="Only confirm after the money has left the company account. The affiliate receives this reference."
                            />
                        ) : (
                            <TextArea
                                label={action === 'approve' ? 'Note (optional)' : action === 'reject' ? 'Reason, shown to the affiliate' : 'What happened, shown to the affiliate'}
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                error={fieldErrors.note ?? fieldErrors.reason}
                            />
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setAction(null)}>
                                Back
                            </Button>
                            <Button type="submit" loading={saving}>
                                {ACTION_TITLES[action]}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
