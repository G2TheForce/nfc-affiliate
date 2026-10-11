import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Card, Field, Modal, Select, Spinner, Stat, StatusBadge } from '@/components/ui';
import type { Paginated } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { METHOD_TYPES, STATUS_HELP, type Payout, type PayoutMethod } from '@/payouts/types';

interface Summary {
    currency: string;
    available_minor: number;
    reserved_minor: number;
    paid_minor: number;
    minimum_minor: number;
    method: PayoutMethod | null;
    open_payout: Payout | null;
    can_request: boolean;
    blocker: string | null;
}

const EMPTY_FORM = { type: 'bank_transfer', account_name: '', bank_name: '', branch: '', account_number: '', wallet_id: '', password: '' };

export default function PayoutsPage() {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [payouts, setPayouts] = useState<Payout[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [cancelling, setCancelling] = useState<Payout | null>(null);
    const [form, setForm] = useState(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(
        () =>
            Promise.all([
                api<{ data: Summary }>('/affiliate/payouts/summary')
                    .then((r) => setSummary(r.data))
                    .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load your payouts.')),
                api<Paginated<Payout>>('/affiliate/payouts')
                    .then((r) => setPayouts(r.data))
                    .catch(() => undefined),
            ]),
        [],
    );

    useEffect(() => {
        void load();
    }, [load]);

    const openEdit = () => {
        reset();
        setForm({ ...EMPTY_FORM, type: summary?.method?.type ?? 'bank_transfer', account_name: summary?.method?.account_name ?? '' });
        setEditing(true);
    };

    const saveMethod = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const wallet = form.type !== 'bank_transfer';
        try {
            await api('/affiliate/payout-method', {
                method: 'PUT',
                body: {
                    type: form.type,
                    account_name: form.account_name,
                    bank_name: wallet ? null : form.bank_name,
                    branch: wallet ? null : form.branch || null,
                    account_number: wallet ? null : form.account_number.replace(/\s/g, ''),
                    wallet_id: wallet ? form.wallet_id.replace(/\s/g, '') : null,
                    password: form.password,
                },
            });
            await load();
            setEditing(false);
            setNotice('Payout details saved. We sent you an email to confirm the change.');
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const requestPayout = async () => {
        reset();
        setSaving(true);
        try {
            await api('/affiliate/payouts', { method: 'POST' });
            await load();
            setConfirming(false);
            setNotice('Payout requested. We will email you when it is reviewed.');
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const cancelPayout = async () => {
        if (!cancelling) return;
        reset();
        setSaving(true);
        try {
            await api(`/affiliate/payouts/${cancelling.id}/cancel`, { method: 'POST' });
            await load();
            setCancelling(null);
            setNotice('Payout request cancelled. The amount is back in your available balance.');
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!summary) return <Spinner />;
    const c = summary.currency;
    const open = summary.open_payout;

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Payouts</h1>
            {notice && <Alert tone="success">{notice}</Alert>}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <Stat label="Available" value={formatMoney(summary.available_minor, c)} hint={`Minimum payout ${formatMoney(summary.minimum_minor, c)}`} />
                <Stat label="In progress" value={formatMoney(summary.reserved_minor, c)} hint="Requested, not yet sent" />
                <Stat label="Paid out" value={formatMoney(summary.paid_minor, c)} />
            </div>

            <Card title="Request a payout">
                {open ? (
                    <div className="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="font-medium text-ink">
                                {formatMoney(open.amount_minor, open.currency)} to {open.destination} <StatusBadge status={open.status} label={open.status_label} />
                            </p>
                            <p className="text-muted">
                                Requested {formatDateTime(open.requested_at)}. {STATUS_HELP[open.status]}
                            </p>
                        </div>
                        {open.can_cancel && (
                            <Button variant="secondary" onClick={() => (reset(), setCancelling(open))}>
                                Cancel request
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3 text-sm">
                        <p className="text-muted">
                            A payout request covers your whole available balance. Our finance team checks it, sends the money to your payout details and emails you the transfer reference.
                        </p>
                        {summary.blocker && <p className="text-ink">{summary.blocker}</p>}
                        {message && <Alert tone="error">{message}</Alert>}
                        {fieldErrors.payout && <Alert tone="error">{fieldErrors.payout}</Alert>}
                        <Button disabled={!summary.can_request} onClick={() => (reset(), setConfirming(true))}>
                            {summary.available_minor > 0 ? `Request ${formatMoney(summary.available_minor, c)}` : 'Request payout'}
                        </Button>
                    </div>
                )}
            </Card>

            <Card title="Payout details">
                <div className="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                    {summary.method ? (
                        <div>
                            <p className="font-medium text-ink">
                                {summary.method.type_label}: {summary.method.masked}
                            </p>
                            <p className="text-muted">
                                In the name of {summary.method.account_name}. Changed {formatDateTime(summary.method.updated_at)}.
                            </p>
                        </div>
                    ) : (
                        <p className="text-muted">Add the bank account or wallet where you want to be paid.</p>
                    )}
                    <Button variant="secondary" onClick={openEdit}>
                        {summary.method ? 'Change' : 'Add payout details'}
                    </Button>
                </div>
                {open && <p className="mt-3 text-xs text-muted">A change applies to future requests. The request in progress keeps the details it was made with.</p>}
            </Card>

            <Card title="Payout history">
                {payouts.length === 0 ? (
                    <p className="text-sm text-muted">No payouts yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {payouts.map((p) => (
                            <li key={p.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink tabular-nums">
                                        {formatMoney(p.amount_minor, p.currency)} <span className="font-normal text-muted">to {p.destination}</span>
                                    </p>
                                    <p className="text-xs text-muted">
                                        Requested {formatDateTime(p.requested_at)}
                                        {p.paid_at && ` · sent ${formatDateTime(p.paid_at)}`}
                                        {p.payment_reference && ` · reference ${p.payment_reference}`}
                                    </p>
                                    {p.reason && <p className="text-xs text-muted">Reason: {p.reason}</p>}
                                </div>
                                <span className="self-start">
                                    <StatusBadge status={p.status} label={p.status_label} />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal title="Request payout" open={confirming} onClose={() => setConfirming(false)}>
                <div className="space-y-3 text-sm">
                    {message && <Alert tone="error">{message}</Alert>}
                    {fieldErrors.payout && <Alert tone="error">{fieldErrors.payout}</Alert>}
                    <p>
                        Request {formatMoney(summary.available_minor, c)} to {summary.method?.masked} ({summary.method?.account_name})?
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setConfirming(false)}>
                            Close
                        </Button>
                        <Button loading={saving} onClick={requestPayout}>
                            Request payout
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal title="Cancel payout request" open={cancelling !== null} onClose={() => setCancelling(null)}>
                <div className="space-y-3 text-sm">
                    {fieldErrors.payout && <Alert tone="error">{fieldErrors.payout}</Alert>}
                    <p>The amount goes back to your available balance and you can request again later.</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setCancelling(null)}>
                            Keep request
                        </Button>
                        <Button loading={saving} onClick={cancelPayout}>
                            Cancel request
                        </Button>
                    </div>
                </div>
            </Modal>

            <Modal title="Payout details" open={editing} onClose={() => setEditing(false)}>
                <form onSubmit={saveMethod} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Select label="Paid by" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} error={fieldErrors.type}>
                        {METHOD_TYPES.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </Select>
                    <Field
                        label="Account holder name"
                        autoComplete="name"
                        value={form.account_name}
                        onChange={(e) => setForm({ ...form, account_name: e.target.value })}
                        error={fieldErrors.account_name}
                        hint="Exactly as it appears on the account."
                    />
                    {form.type === 'bank_transfer' ? (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="Bank" value={form.bank_name} onChange={(e) => setForm({ ...form, bank_name: e.target.value })} error={fieldErrors.bank_name} />
                                <Field label="Branch (optional)" value={form.branch} onChange={(e) => setForm({ ...form, branch: e.target.value })} error={fieldErrors.branch} />
                            </div>
                            <Field
                                label="Account number"
                                inputMode="numeric"
                                autoComplete="off"
                                value={form.account_number}
                                onChange={(e) => setForm({ ...form, account_number: e.target.value })}
                                error={fieldErrors.account_number}
                            />
                        </>
                    ) : (
                        <Field
                            label="Wallet mobile number"
                            inputMode="tel"
                            autoComplete="off"
                            value={form.wallet_id}
                            onChange={(e) => setForm({ ...form, wallet_id: e.target.value })}
                            error={fieldErrors.wallet_id}
                            hint="The 10-digit number registered with the wallet."
                        />
                    )}
                    <Field
                        label="Your password"
                        type="password"
                        autoComplete="current-password"
                        value={form.password}
                        onChange={(e) => setForm({ ...form, password: e.target.value })}
                        error={fieldErrors.password}
                        hint="Needed to change where your money is sent."
                    />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditing(false)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            Save
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
