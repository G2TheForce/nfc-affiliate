import { useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Select, StatusBadge, TextArea } from '@/components/ui';
import { rateLabel } from '@/commissions/types';
import { api } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { PAYMENT_METHODS, type AdminOrder } from './orderTypes';

const majorText = (minor: number) => (minor / 100).toFixed(2);

/** Payment, refunds and commissions for one order, with the staff actions that change them. */
export function OrderMoneyPanels({ order, onChange }: { order: AdminOrder; onChange: (order: AdminOrder) => void }) {
    const { can } = useAuth();
    const [dialog, setDialog] = useState<'payment' | 'refund' | null>(null);
    const [payment, setPayment] = useState({ method: 'bank_transfer', reference: '', amount: '', note: '' });
    const [refund, setRefund] = useState<{ reference: string; reason: string; lines: Record<string, string>; shipping: string }>({ reference: '', reason: '', lines: {}, shipping: '' });
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const paid = ['paid', 'partially_refunded'].includes(order.status);
    // Lines with an amount, in the order they are sent (errors come back as items.N).
    const refundLines = Object.keys(refund.lines).filter((id) => (refund.lines[id] ?? '').trim() !== '' && Number(refund.lines[id]) > 0);
    const shippingRefunded = (order.refunds ?? []).reduce((sum, r) => sum + r.shipping_minor, 0);

    const openPayment = () => {
        reset();
        setPayment({ method: 'bank_transfer', reference: '', amount: majorText(order.total_minor), note: '' });
        setDialog('payment');
    };

    const openRefund = () => {
        reset();
        setRefund({ reference: '', reason: '', lines: {}, shipping: '' });
        setDialog('refund');
    };

    const submitPayment = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        try {
            const r = await api<{ data: AdminOrder }>(`/admin/orders/${order.id}/payments`, {
                method: 'POST',
                body: { method: payment.method, reference: payment.reference, amount_minor: toMinor(payment.amount), note: payment.note || null },
            });
            onChange(r.data);
            setDialog(null);
            setNotice(r.data.commissions?.length ? 'Payment recorded. Commission was created and is pending.' : 'Payment recorded.');
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const submitRefund = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const items = refundLines.map((item_id) => ({ item_id, amount_minor: toMinor(refund.lines[item_id] ?? '0') }));
        try {
            const r = await api<{ data: AdminOrder; meta?: { commission_reversed_minor: number } }>(`/admin/orders/${order.id}/refunds`, {
                method: 'POST',
                body: { reference: refund.reference, reason: refund.reason, items, shipping_minor: refund.shipping ? toMinor(refund.shipping) : 0 },
            });
            onChange(r.data);
            setDialog(null);
            const reversed = r.meta?.commission_reversed_minor ?? 0;
            setNotice(reversed > 0 ? `Refund recorded. ${formatMoney(reversed, order.currency)} of commission was reversed.` : 'Refund recorded.');
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            {notice && <Alert tone="success">{notice}</Alert>}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Payment">
                    {order.payment ? (
                        <dl className="space-y-1 text-sm">
                            <div>
                                <dt className="inline text-muted">Received: </dt>
                                <dd className="inline">
                                    {formatMoney(order.payment.amount_minor, order.currency)} by {order.payment.method_label}
                                </dd>
                            </div>
                            <div>
                                <dt className="inline text-muted">Reference: </dt>
                                <dd className="inline font-mono">{order.payment.reference}</dd>
                            </div>
                            <div>
                                <dt className="inline text-muted">Confirmed: </dt>
                                <dd className="inline">
                                    {formatDateTime(order.payment.confirmed_at)} by {order.payment.confirmed_by}
                                </dd>
                            </div>
                            {order.payment.note && <p className="text-muted">{order.payment.note}</p>}
                        </dl>
                    ) : (
                        <p className="text-sm text-muted">{order.status === 'cancelled' ? 'Cancelled before payment.' : 'No payment recorded yet.'}</p>
                    )}
                    {(order.refunds ?? []).length > 0 && (
                        <div className="mt-4 border-t border-line pt-3">
                            <h3 className="mb-2 text-sm font-semibold text-ink">Refunds ({formatMoney(order.refunded_minor, order.currency)})</h3>
                            <ul className="space-y-2 text-sm">
                                {order.refunds!.map((r) => (
                                    <li key={r.id}>
                                        <p>
                                            {formatMoney(r.amount_minor, order.currency)} · <span className="font-mono">{r.reference}</span> · {formatDate(r.completed_at)} by {r.recorded_by}
                                        </p>
                                        <p className="text-muted">
                                            {[...r.items.map((i) => `${i.sku} ${formatMoney(i.amount_minor, order.currency)}`), r.shipping_minor ? `delivery ${formatMoney(r.shipping_minor, order.currency)}` : null]
                                                .filter(Boolean)
                                                .join(', ')}{' '}
                                            · {r.reason}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="mt-4 flex flex-wrap gap-2">
                        {can('payments.confirm') && order.status === 'pending_payment' && <Button onClick={openPayment}>Confirm payment received</Button>}
                        {can('refunds.record') && paid && (
                            <Button variant="secondary" onClick={openRefund}>
                                Record refund
                            </Button>
                        )}
                    </div>
                </Card>
                {order.commissions !== undefined && (
                    <Card title="Commission">
                        {order.commissions.length === 0 ? (
                            <p className="text-sm text-muted">{order.payment ? 'No commission on this order.' : 'Commission is created when the payment is confirmed.'}</p>
                        ) : (
                            <ul className="divide-y divide-line text-sm">
                                {order.commissions.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-start justify-between gap-2 py-2">
                                        <div>
                                            <p className="font-medium text-ink">
                                                {c.product_name}: {rateLabel(c.kind, c.rate_bp, c.fixed_minor, c.currency)} of {formatMoney(c.base_minor, c.currency)}
                                            </p>
                                            <p className="text-muted">
                                                {c.affiliate?.display_name} · rule “{c.rule?.name}” v{c.rule?.version}
                                                {c.reversed_minor > 0 && ` · ${formatMoney(c.reversed_minor, c.currency)} reversed`}
                                                {c.on_hold && ' · on hold'}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-semibold tabular-nums">{formatMoney(c.net_minor, c.currency)}</span>
                                            <StatusBadge status={c.status} label={c.status_label} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </div>

            <Modal title="Confirm payment received" open={dialog === 'payment'} onClose={() => setDialog(null)}>
                <form onSubmit={submitPayment} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    {fieldErrors.order && <Alert tone="error">{fieldErrors.order}</Alert>}
                    <p className="text-sm text-muted">
                        Only confirm money you have seen arrive: in the bank statement, as cash in hand, or in the wallet's merchant dashboard. This marks the order paid and creates
                        any commission.
                    </p>
                    <Select label="How it was paid" value={payment.method} onChange={(e) => setPayment({ ...payment, method: e.target.value })} error={fieldErrors.method}>
                        {PAYMENT_METHODS.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </Select>
                    <Field label="Reference" value={payment.reference} onChange={(e) => setPayment({ ...payment, reference: e.target.value })} error={fieldErrors.reference} hint="Bank or wallet transaction ID, or receipt number" />
                    <Field label="Amount received (NPR)" inputMode="decimal" value={payment.amount} onChange={(e) => setPayment({ ...payment, amount: e.target.value })} error={fieldErrors.amount_minor} hint={`Order total ${formatMoney(order.total_minor, order.currency)}`} />
                    <TextArea label="Note (optional)" value={payment.note} onChange={(e) => setPayment({ ...payment, note: e.target.value })} error={fieldErrors.note} />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDialog(null)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            Confirm payment
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal title="Record refund" open={dialog === 'refund'} onClose={() => setDialog(null)}>
                <form onSubmit={submitRefund} className="max-h-[75vh] space-y-3 overflow-y-auto">
                    {message && <Alert tone="error">{message}</Alert>}
                    {(fieldErrors.order || fieldErrors.items) && <Alert tone="error">{fieldErrors.order ?? fieldErrors.items}</Alert>}
                    <p className="text-sm text-muted">Record a refund after the money has gone back to the customer. Commission is reversed only for the refunded amounts.</p>
                    {order.items?.map((item) => {
                        const left = item.line_total_minor - item.refunded_minor;
                        return (
                            <Field
                                key={item.id}
                                label={`${item.name} × ${item.quantity} (NPR)`}
                                inputMode="decimal"
                                value={refund.lines[item.id] ?? ''}
                                disabled={left <= 0}
                                onChange={(e) => setRefund({ ...refund, lines: { ...refund.lines, [item.id]: e.target.value } })}
                                error={fieldErrors[`items.${refundLines.indexOf(item.id)}.amount_minor`] ?? fieldErrors[`items.${refundLines.indexOf(item.id)}.item_id`]}
                                hint={left > 0 ? `Up to ${formatMoney(left, order.currency)}` : 'Fully refunded'}
                            />
                        );
                    })}
                    {order.shipping_minor - shippingRefunded > 0 && (
                        <Field
                            label="Delivery charge (NPR)"
                            inputMode="decimal"
                            value={refund.shipping}
                            onChange={(e) => setRefund({ ...refund, shipping: e.target.value })}
                            error={fieldErrors.shipping_minor}
                            hint={`Up to ${formatMoney(order.shipping_minor - shippingRefunded, order.currency)}`}
                        />
                    )}
                    <Field label="Refund reference" value={refund.reference} onChange={(e) => setRefund({ ...refund, reference: e.target.value })} error={fieldErrors.reference} hint="Bank or wallet transaction ID of the refund" />
                    <TextArea label="Reason" value={refund.reason} onChange={(e) => setRefund({ ...refund, reason: e.target.value })} error={fieldErrors.reason} />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDialog(null)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            Record refund
                        </Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
