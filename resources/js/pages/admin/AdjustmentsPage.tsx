import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Select, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Adjustment {
    id: string;
    affiliate: { id: string; display_name: string; referral_code: string | null };
    commission_id: string | null;
    amount_minor: number;
    currency: string;
    reason: string;
    status: string;
    created_by: string | null;
    can_decide: boolean;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    created_at: string;
}

const STATUS_LABELS: Record<string, string> = { pending_approval: 'Waiting for approval', approved: 'Approved', rejected: 'Rejected' };

export default function AdjustmentsPage() {
    const { can } = useAuth();
    const [rows, setRows] = useState<Adjustment[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [affiliates, setAffiliates] = useState<{ id: string; display_name: string; referral_code: string | null }[]>([]);
    const [proposing, setProposing] = useState(false);
    const [form, setForm] = useState({ affiliate_id: '', direction: 'credit', amount: '', commission_id: '', reason: '' });
    const [deciding, setDeciding] = useState<{ row: Adjustment; approve: boolean } | null>(null);
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(() => {
        api<{ data: Adjustment[] }>('/admin/adjustments')
            .then((r) => setRows(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load adjustments.'));
    }, []);

    useEffect(load, [load]);

    const openPropose = () => {
        reset();
        setForm({ affiliate_id: '', direction: 'credit', amount: '', commission_id: '', reason: '' });
        setProposing(true);
        if (affiliates.length === 0) {
            api<{ data: { id: string; display_name: string; referral_code: string | null }[] }>('/admin/affiliates?per_page=100')
                .then((r) => setAffiliates(r.data))
                .catch(() => undefined);
        }
    };

    const propose = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const amount = toMinor(form.amount || '0') * (form.direction === 'debit' ? -1 : 1);
        try {
            await api('/admin/adjustments', {
                method: 'POST',
                body: { affiliate_id: form.affiliate_id, amount_minor: amount, reason: form.reason, commission_id: form.commission_id.trim() || null },
            });
            setProposing(false);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const decide = async (e: FormEvent) => {
        e.preventDefault();
        if (!deciding) return;
        reset();
        setSaving(true);
        try {
            await api(`/admin/adjustments/${deciding.row.id}/${deciding.approve ? 'approve' : 'reject'}`, { method: 'POST', body: { note: note || null } });
            setDeciding(null);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!rows) return <Spinner />;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Adjustments</h1>
                {can('commissions.adjust') && <Button onClick={openPropose}>Propose adjustment</Button>}
            </div>
            <p className="text-sm text-muted">
                Balances are never edited. A correction is a separate entry that a second finance person must approve before it changes the affiliate's available balance.
            </p>
            <Card>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted">No adjustments yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rows.map((r) => (
                            <li key={r.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink">
                                        {r.affiliate.display_name} ({r.affiliate.referral_code}) · <span className="tabular-nums">{r.amount_minor > 0 ? '+' : '−'}{formatMoney(Math.abs(r.amount_minor), r.currency)}</span>
                                    </p>
                                    <p className="text-muted">{r.reason}</p>
                                    <p className="text-xs text-muted">
                                        Proposed by {r.created_by} on {formatDateTime(r.created_at)}
                                        {r.decided_by && ` · ${r.status === 'approved' ? 'approved' : 'rejected'} by ${r.decided_by}${r.decision_note ? `: ${r.decision_note}` : ''}`}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge status={r.status} label={STATUS_LABELS[r.status]} />
                                    {can('commissions.approve') && r.status === 'pending_approval' && (
                                        <>
                                            {r.can_decide && <Button onClick={() => (reset(), setNote(''), setDeciding({ row: r, approve: true }))}>Approve</Button>}
                                            <Button variant="secondary" onClick={() => (reset(), setNote(''), setDeciding({ row: r, approve: false }))}>
                                                Reject
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Modal title="Propose adjustment" open={proposing} onClose={() => setProposing(false)}>
                <form onSubmit={propose} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Select label="Affiliate" value={form.affiliate_id} onChange={(e) => setForm({ ...form, affiliate_id: e.target.value })} error={fieldErrors.affiliate_id}>
                        <option value="">Choose…</option>
                        {affiliates.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.display_name} ({a.referral_code ?? 'no code'})
                            </option>
                        ))}
                    </Select>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Select label="Direction" value={form.direction} onChange={(e) => setForm({ ...form, direction: e.target.value })}>
                            <option value="credit">Add to balance</option>
                            <option value="debit">Deduct from balance</option>
                        </Select>
                        <Field label="Amount (NPR)" inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} error={fieldErrors.amount_minor} />
                    </div>
                    <Field
                        label="Related commission ID (optional)"
                        value={form.commission_id}
                        onChange={(e) => setForm({ ...form, commission_id: e.target.value })}
                        error={fieldErrors.commission_id}
                        hint="From the commissions list, when the correction is about one sale."
                    />
                    <TextArea label="Reason" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={fieldErrors.reason} hint="Shown to the approver and kept in the audit log." />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setProposing(false)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            Submit for approval
                        </Button>
                    </div>
                </form>
            </Modal>
            <Modal title={deciding?.approve ? 'Approve adjustment' : 'Reject adjustment'} open={deciding !== null} onClose={() => setDeciding(null)}>
                {deciding && (
                    <form onSubmit={decide} className="space-y-3">
                        {message && <Alert tone="error">{message}</Alert>}
                        {fieldErrors.adjustment && <Alert tone="error">{fieldErrors.adjustment}</Alert>}
                        <p className="text-sm">
                            {deciding.row.affiliate.display_name}: {deciding.row.amount_minor > 0 ? 'add' : 'deduct'} {formatMoney(Math.abs(deciding.row.amount_minor), deciding.row.currency)}. {deciding.row.reason}
                        </p>
                        <TextArea label={deciding.approve ? 'Note (optional)' : 'Why it is rejected'} value={note} onChange={(e) => setNote(e.target.value)} error={fieldErrors.note} />
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setDeciding(null)}>
                                Close
                            </Button>
                            <Button type="submit" loading={saving}>
                                {deciding.approve ? 'Approve' : 'Reject'}
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>
        </div>
    );
}
