import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { Alert, Button, Card, Modal, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Flag {
    id: string;
    signal: string;
    signal_label: string;
    guidance: string;
    severity: 'low' | 'medium' | 'high';
    details: Record<string, string | number>;
    subject: { type: string; id: string } | null;
    status: string;
    status_label: string;
    affiliate: { id: string; display_name: string; referral_code: string | null } | null;
    detected_at: string;
    reviewed_by: string | null;
    reviewed_at: string | null;
    review_note: string | null;
}

const DETAIL_LABELS: Record<string, string> = {
    period_days: 'Period (days)',
    unique_clicks: 'Unique clicks',
    orders_from_clicks: 'Orders from clicks',
    conversion_percent: 'Conversion %',
    threshold_percent: 'Limit %',
    paid_orders: 'Paid orders',
    threshold_clicks: 'Limit (clicks)',
    refunded_orders: 'Refunded orders',
    refund_percent: 'Refunded %',
    last_7_days: 'Last 7 days',
    weekly_average_before: 'Weekly average before',
    threshold_multiple: 'Limit (times the average)',
    amount: 'Amount',
    threshold: 'Limit',
    details_changed_at: 'Details changed',
    destination: 'New destination',
    window_days: 'Window (days)',
};

const SEVERITY: Record<Flag['severity'], string> = { low: 'Low', medium: 'Medium', high: 'High' };
const FILTERS = [
    ['open', 'Open'],
    ['confirmed', 'Confirmed'],
    ['dismissed', 'Dismissed'],
    ['all', 'All'],
] as const;

export default function FraudPage() {
    const [status, setStatus] = useState<string>('open');
    const [rows, setRows] = useState<Flag[] | null>(null);
    const [open, setOpen] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [reviewing, setReviewing] = useState<{ flag: Flag; outcome: 'dismissed' | 'confirmed' } | null>(null);
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(() => {
        api<{ data: Flag[]; meta: { open: number } }>(`/admin/fraud-flags?status=${status}`)
            .then((r) => (setRows(r.data), setOpen(r.meta.open)))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load fraud flags.'));
    }, [status]);

    useEffect(load, [load]);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!reviewing) return;
        reset();
        setSaving(true);
        try {
            await api(`/admin/fraud-flags/${reviewing.flag.id}/review`, { method: 'POST', body: { outcome: reviewing.outcome, note } });
            setReviewing(null);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Fraud review</h1>
            <p className="text-sm text-muted">
                The system checks every night for unusual conversion, clicks without orders, refund rates and earnings, and checks each payout request as it comes in. A flag never
                blocks anything by itself. Look into it, then hold or void commission from the affiliate's page if needed, and record what you found.
            </p>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
                {FILTERS.map(([key, label]) => (
                    <Button key={key} variant={status === key ? 'primary' : 'secondary'} onClick={() => (setRows(null), setStatus(key))}>
                        {label}
                        {key === 'open' && open > 0 ? ` (${open})` : ''}
                    </Button>
                ))}
            </div>
            <Card>
                {!rows ? (
                    <Spinner />
                ) : rows.length === 0 ? (
                    <p className="text-sm text-muted">{status === 'open' ? 'Nothing to review.' : 'No flags.'}</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rows.map((f) => (
                            <li key={f.id} className="space-y-2 py-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-medium text-ink">
                                            {f.signal_label} · <span className="text-muted">{SEVERITY[f.severity]} severity</span>
                                        </p>
                                        {f.affiliate && (
                                            <p>
                                                <Link to={`/admin/affiliates/${f.affiliate.id}`} className="font-medium text-brand-700 underline">
                                                    {f.affiliate.display_name}
                                                </Link>{' '}
                                                <span className="text-muted">({f.affiliate.referral_code ?? 'no code'})</span>
                                            </p>
                                        )}
                                        <p className="text-xs text-muted">Raised {formatDateTime(f.detected_at)}</p>
                                    </div>
                                    <StatusBadge status={f.status} label={f.status_label} />
                                </div>
                                <dl className="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2">
                                    {Object.entries(f.details).map(([k, v]) => (
                                        <div key={k} className="flex justify-between gap-3 sm:justify-start">
                                            <dt className="text-muted">{DETAIL_LABELS[k] ?? k}</dt>
                                            <dd className="font-medium text-ink tabular-nums">{k.endsWith('_at') ? formatDateTime(String(v)) : String(v)}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-muted">What to check: {f.guidance}</p>
                                {f.subject?.type === 'payout' && (
                                    <p>
                                        <Link to="/admin/payouts" className="text-brand-700 underline">
                                            Go to payouts
                                        </Link>
                                    </p>
                                )}
                                {f.status === 'open' ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button onClick={() => (reset(), setNote(''), setReviewing({ flag: f, outcome: 'confirmed' }))}>Confirm problem</Button>
                                        <Button variant="secondary" onClick={() => (reset(), setNote(''), setReviewing({ flag: f, outcome: 'dismissed' }))}>
                                            Dismiss
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted">
                                        {f.status_label} by {f.reviewed_by} on {f.reviewed_at && formatDateTime(f.reviewed_at)}: {f.review_note}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Modal title={reviewing?.outcome === 'confirmed' ? 'Confirm problem' : 'Dismiss flag'} open={reviewing !== null} onClose={() => setReviewing(null)}>
                <form onSubmit={submit} className="space-y-3" noValidate>
                    {(message || fieldErrors.status) && <Alert tone="error">{message ?? fieldErrors.status}</Alert>}
                    <p className="text-sm text-muted">
                        {reviewing?.outcome === 'confirmed'
                            ? 'Confirming only records your finding. Hold or void the commission from the affiliate page, and reject the payout if one is waiting.'
                            : 'Say what you checked and why it is fine.'}
                    </p>
                    <TextArea label="What you found" value={note} onChange={(e) => setNote(e.target.value)} error={fieldErrors.note} required />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" loading={saving}>
                            Save
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setReviewing(null)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
