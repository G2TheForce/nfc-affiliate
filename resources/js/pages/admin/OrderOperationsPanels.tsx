import { useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Select, StatusBadge, TextArea } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDateTime, formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { PROVIDER_LABELS, type AdminOrder, type OrderDispute, type PaymentAttempt } from './orderTypes';

type Dialog =
    | { kind: 'ship' }
    | { kind: 'dispute' }
    | { kind: 'resolve-dispute'; dispute: OrderDispute }
    | { kind: 'resolve-attempt'; attempt: PaymentAttempt };

/** Online payment attempts, delivery steps and disputes for one order. */
export function OrderOperationsPanels({ order, onChange, reload }: { order: AdminOrder; onChange: (order: AdminOrder) => void; reload: () => Promise<void> }) {
    const { can } = useAuth();
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [form, setForm] = useState({ carrier: '', tracking: '', reference: '', amount: '', reason: '', outcome: 'won', note: '' });
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const attempts = order.payment_attempts ?? [];
    const disputes = order.disputes ?? [];
    const f = order.fulfilment;
    const closed = ['cancelled', 'refunded'].includes(order.status);
    const disputable = ['paid', 'partially_refunded'].includes(order.status) && !disputes.some((d) => d.status === 'open');

    const open = (d: Dialog) => {
        reset();
        setForm({ carrier: f?.carrier ?? '', tracking: f?.tracking_number ?? '', reference: '', amount: '', reason: '', outcome: 'won', note: '' });
        setDialog(d);
    };

    const fulfil = async (status: 'processing' | 'delivered') => {
        setActionError(null);
        setBusy(status);
        try {
            const r = await api<{ data: AdminOrder }>(`/admin/orders/${order.id}/fulfilment`, { method: 'POST', body: { status } });
            onChange(r.data);
            setNotice(status === 'processing' ? 'Marked as being prepared.' : 'Marked as delivered.');
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'That did not work. Please try again.');
        } finally {
            setBusy(null);
        }
    };

    const recheck = async (attempt: PaymentAttempt) => {
        setActionError(null);
        setBusy(attempt.id);
        try {
            const r = await api<{ data: PaymentAttempt }>(`/admin/payment-attempts/${attempt.id}/recheck`, { method: 'POST' });
            await reload();
            const name = PROVIDER_LABELS[attempt.provider] ?? attempt.provider;
            setNotice(
                r.data.provider_status
                    ? `${name} says ${r.data.provider_status}. The payment is ${r.data.status_label.toLowerCase()}.`
                    : `${name} did not answer. It will be checked again automatically.`,
            );
        } catch (err) {
            setActionError(err instanceof Error ? err.message : 'The provider could not be reached. Please try again.');
        } finally {
            setBusy(null);
        }
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!dialog) return;
        reset();
        setBusy('dialog');
        try {
            if (dialog.kind === 'resolve-attempt') {
                await api(`/admin/payment-attempts/${dialog.attempt.id}/resolve`, { method: 'POST', body: { note: form.note } });
                await reload();
                setNotice('Review marked as dealt with.');
            } else {
                const r =
                    dialog.kind === 'ship'
                        ? await api<{ data: AdminOrder }>(`/admin/orders/${order.id}/fulfilment`, { method: 'POST', body: { status: 'shipped', carrier: form.carrier, tracking_number: form.tracking || null } })
                        : dialog.kind === 'dispute'
                          ? await api<{ data: AdminOrder }>(`/admin/orders/${order.id}/disputes`, { method: 'POST', body: { reference: form.reference, amount_minor: toMinor(form.amount), reason: form.reason } })
                          : await api<{ data: AdminOrder }>(`/admin/disputes/${dialog.dispute.id}/resolve`, { method: 'POST', body: { outcome: form.outcome, note: form.note } });
                onChange(r.data);
                setNotice(
                    dialog.kind === 'ship'
                        ? 'Marked as shipped. The customer was emailed.'
                        : dialog.kind === 'dispute'
                          ? 'Dispute opened. Commission on this order is on hold.'
                          : form.outcome === 'lost'
                            ? 'Dispute closed as lost. A refund was recorded and commission reversed.'
                            : 'Dispute closed as won. Commission is released.',
                );
            }
            setDialog(null);
        } catch (err) {
            capture(err);
        } finally {
            setBusy(null);
        }
    };

    const title = dialog?.kind === 'ship' ? 'Mark as shipped' : dialog?.kind === 'dispute' ? 'Open a dispute' : dialog?.kind === 'resolve-dispute' ? 'Close dispute' : 'Mark review as dealt with';

    return (
        <>
            {notice && <Alert tone="success">{notice}</Alert>}
            {actionError && <Alert tone="error">{actionError}</Alert>}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Delivery">
                    <div className="mb-3">
                        <StatusBadge status={order.fulfilment_status} label={order.fulfilment_label} />
                    </div>
                    <dl className="space-y-1 text-sm">
                        {f?.processing_at && (
                            <div>
                                <dt className="inline text-muted">Preparing since: </dt>
                                <dd className="inline">{formatDateTime(f.processing_at)}</dd>
                            </div>
                        )}
                        {f?.shipped_at && (
                            <div>
                                <dt className="inline text-muted">Shipped: </dt>
                                <dd className="inline">
                                    {formatDateTime(f.shipped_at)} with {f.carrier}
                                    {f.tracking_number && (
                                        <>
                                            {' '}
                                            (tracking <span className="font-mono">{f.tracking_number}</span>)
                                        </>
                                    )}
                                </dd>
                            </div>
                        )}
                        {f?.delivered_at && (
                            <div>
                                <dt className="inline text-muted">Delivered: </dt>
                                <dd className="inline">{formatDateTime(f.delivered_at)}</dd>
                            </div>
                        )}
                    </dl>
                    {order.fulfilment_status === 'unfulfilled' && <p className="text-sm text-muted">{closed ? 'Not sent.' : 'Not started yet.'}</p>}
                    {can('orders.manage') && !closed && order.fulfilment_status !== 'delivered' && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {order.fulfilment_status === 'unfulfilled' && (
                                <Button variant="secondary" loading={busy === 'processing'} onClick={() => fulfil('processing')}>
                                    Start preparing
                                </Button>
                            )}
                            {order.fulfilment_status !== 'shipped' && <Button onClick={() => open({ kind: 'ship' })}>Mark as shipped</Button>}
                            {order.fulfilment_status === 'shipped' && (
                                <Button loading={busy === 'delivered'} onClick={() => fulfil('delivered')}>
                                    Mark as delivered
                                </Button>
                            )}
                        </div>
                    )}
                </Card>

                <Card title="Online payments">
                    {attempts.length === 0 ? (
                        <p className="text-sm text-muted">The customer has not tried to pay online.</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {attempts.map((a) => (
                                <li key={a.id} className="space-y-1 py-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium text-ink">
                                            {PROVIDER_LABELS[a.provider] ?? a.provider} · {formatMoney(a.amount_minor, a.currency)}
                                        </span>
                                        <StatusBadge status={a.status} label={a.status_label} />
                                    </div>
                                    <p className="text-muted">
                                        Started {formatDateTime(a.created_at)}
                                        {a.provider_status && ` · provider says ${a.provider_status}`}
                                        {a.provider_transaction_id && (
                                            <>
                                                {' '}
                                                · transaction <span className="font-mono">{a.provider_transaction_id}</span>
                                            </>
                                        )}
                                        {a.last_checked_at && ` · checked ${a.check_count} time${a.check_count === 1 ? '' : 's'}, last ${formatDateTime(a.last_checked_at)}`}
                                    </p>
                                    {a.review_reason && (
                                        <Alert tone={a.reviewed_at ? 'info' : 'error'}>
                                            {a.review_reason}
                                            {a.reviewed_at && ` Dealt with by ${a.reviewed_by} on ${formatDateTime(a.reviewed_at)}: ${a.review_note}`}
                                        </Alert>
                                    )}
                                    {can('payments.confirm') && (
                                        <div className="flex flex-wrap gap-2 pt-1">
                                            {['initiated', 'pending'].includes(a.status) && (
                                                <Button variant="secondary" loading={busy === a.id} onClick={() => recheck(a)}>
                                                    Check with {PROVIDER_LABELS[a.provider] ?? a.provider} now
                                                </Button>
                                            )}
                                            {a.review_reason && !a.reviewed_at && (
                                                <Button variant="secondary" onClick={() => open({ kind: 'resolve-attempt', attempt: a })}>
                                                    Mark as dealt with
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {(disputes.length > 0 || (can('refunds.record') && disputable)) && (
                    <Card title="Disputes and chargebacks">
                        {disputes.length === 0 && <p className="text-sm text-muted">No disputes. Open one when the bank or wallet tells you the customer is disputing the payment.</p>}
                        <ul className="divide-y divide-line text-sm">
                            {disputes.map((d) => (
                                <li key={d.id} className="space-y-1 py-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium text-ink">
                                            <span className="font-mono">{d.reference}</span> · {formatMoney(d.amount_minor, order.currency)}
                                        </span>
                                        <StatusBadge status={d.status} label={d.status_label} />
                                    </div>
                                    <p className="text-muted">
                                        {d.reason} · opened {d.opened_at && formatDateTime(d.opened_at)} by {d.opened_by}
                                        {d.status === 'open' && d.held_commissions > 0 && ` · ${d.held_commissions} commission${d.held_commissions === 1 ? '' : 's'} on hold`}
                                    </p>
                                    {d.resolved_at && (
                                        <p className="text-muted">
                                            Closed {formatDateTime(d.resolved_at)} by {d.resolved_by}: {d.resolution_note}
                                        </p>
                                    )}
                                    {d.status === 'open' && can('refunds.record') && (
                                        <Button variant="secondary" onClick={() => open({ kind: 'resolve-dispute', dispute: d })}>
                                            Close dispute
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {can('refunds.record') && disputable && (
                            <Button variant="secondary" className="mt-3" onClick={() => open({ kind: 'dispute' })}>
                                Open a dispute
                            </Button>
                        )}
                    </Card>
                )}
            </div>

            <Modal title={title} open={dialog !== null} onClose={() => setDialog(null)}>
                <form onSubmit={submit} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    {(fieldErrors.order || fieldErrors.attempt || fieldErrors.dispute || fieldErrors.status) && (
                        <Alert tone="error">{fieldErrors.order ?? fieldErrors.attempt ?? fieldErrors.dispute ?? fieldErrors.status}</Alert>
                    )}
                    {dialog?.kind === 'ship' && (
                        <>
                            <p className="text-sm text-muted">The customer gets an email with the courier and tracking number.</p>
                            <Field label="Courier" value={form.carrier} onChange={(e) => setForm({ ...form, carrier: e.target.value })} error={fieldErrors.carrier} hint="For example Nepal Can Move, Pathao or own rider" />
                            <Field label="Tracking number (optional)" value={form.tracking} onChange={(e) => setForm({ ...form, tracking: e.target.value })} error={fieldErrors.tracking_number} />
                        </>
                    )}
                    {dialog?.kind === 'dispute' && (
                        <>
                            <p className="text-sm text-muted">Commission on this order is held until you close the dispute. Nothing is refunded yet.</p>
                            <Field label="Case reference" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} error={fieldErrors.reference} hint="The bank's or wallet's case number" />
                            <Field
                                label="Disputed amount (NPR)"
                                inputMode="decimal"
                                value={form.amount}
                                onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                error={fieldErrors.amount_minor}
                                hint={`Up to ${formatMoney(order.total_minor - order.refunded_minor, order.currency)}`}
                            />
                            <TextArea label="What the customer claims" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} error={fieldErrors.reason} />
                        </>
                    )}
                    {dialog?.kind === 'resolve-dispute' && (
                        <>
                            <Select label="Outcome" value={form.outcome} onChange={(e) => setForm({ ...form, outcome: e.target.value })} error={fieldErrors.outcome}>
                                <option value="won">Won: we keep the money</option>
                                <option value="lost">Lost: the money went back to the customer</option>
                            </Select>
                            {form.outcome === 'lost' && (
                                <p className="text-sm text-muted">
                                    A refund of {formatMoney(dialog.dispute.amount_minor, order.currency)} is recorded and the matching commission is reversed. This cannot be undone.
                                </p>
                            )}
                            <TextArea label="Note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} error={fieldErrors.note} hint="Recorded in the audit log." />
                        </>
                    )}
                    {dialog?.kind === 'resolve-attempt' && (
                        <>
                            <p className="text-sm text-muted">{dialog.attempt.review_reason}</p>
                            <TextArea label="What you did" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} error={fieldErrors.note} hint="For example: refunded in the merchant portal, reference ..." />
                        </>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDialog(null)}>
                            Close
                        </Button>
                        <Button type="submit" loading={busy === 'dialog'}>
                            {title}
                        </Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
