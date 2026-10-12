import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Modal, Select, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { OrderMoneyPanels } from './OrderMoneyPanels';
import { OrderOperationsPanels } from './OrderOperationsPanels';
import { BLOCK_REASONS, type AdminOrder } from './orderTypes';

interface AffiliateOption {
    id: string;
    display_name: string;
    referral_code: string | null;
}

export default function OrderDetailPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const [order, setOrder] = useState<AdminOrder | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [dialog, setDialog] = useState<'cancel' | 'attribute' | null>(null);
    const [reason, setReason] = useState('');
    const [affiliateId, setAffiliateId] = useState('');
    const [affiliates, setAffiliates] = useState<AffiliateOption[]>([]);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(
        () =>
            api<{ data: AdminOrder }>(`/admin/orders/${id}`)
                .then((r) => setOrder(r.data))
                .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load the order.')),
        [id],
    );

    useEffect(() => {
        load();
    }, [load]);

    const openAttribute = () => {
        reset();
        setReason('');
        setDialog('attribute');
        if (affiliates.length === 0) {
            api<{ data: AffiliateOption[] }>('/admin/affiliates?status=approved&per_page=100').then((r) => setAffiliates(r.data)).catch(() => undefined);
        }
    };

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        try {
            const r =
                dialog === 'cancel'
                    ? await api<{ data: AdminOrder }>(`/admin/orders/${id}/cancel`, { method: 'POST', body: { reason } })
                    : await api<{ data: AdminOrder }>(`/admin/orders/${id}/attribution`, { method: 'POST', body: { affiliate_id: affiliateId, reason } });
            setOrder(r.data);
            setDialog(null);
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!order) return <Spinner />;
    const a = order.attribution;

    return (
        <div className="space-y-6">
            <Link to="/admin/orders" className="text-sm text-muted hover:text-ink">
                ← Orders
            </Link>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">{order.order_number}</h1>
                <StatusBadge status={order.status} label={order.status_label} />
            </div>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card title="Order">
                    <ul className="divide-y divide-line text-sm">
                        {order.items?.map((i) => (
                            <li key={i.id} className="flex justify-between gap-2 py-2">
                                <span>
                                    {i.name} × {i.quantity}
                                    {i.discount_minor > 0 && <span className="block text-xs text-muted">discount {formatMoney(i.discount_minor, order.currency)}</span>}
                                    {i.refunded_minor > 0 && <span className="block text-xs text-muted">refunded {formatMoney(i.refunded_minor, order.currency)}</span>}
                                </span>
                                <span className="tabular-nums">{formatMoney(i.line_total_minor, order.currency)}</span>
                            </li>
                        ))}
                    </ul>
                    <dl className="mt-3 space-y-1 border-t border-line pt-3 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-muted">Discount{order.coupon_code ? ` (${order.coupon_code})` : ''}</dt>
                            <dd className="tabular-nums">{formatMoney(order.discount_minor, order.currency)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-muted">Delivery</dt>
                            <dd className="tabular-nums">{formatMoney(order.shipping_minor, order.currency)}</dd>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <dt>Total</dt>
                            <dd className="tabular-nums">{formatMoney(order.total_minor, order.currency)}</dd>
                        </div>
                    </dl>
                    <p className="mt-3 text-sm text-muted">
                        Placed {formatDateTime(order.placed_at)} by {order.customer_name} ({order.customer_email}, {order.customer_phone}), {order.shipping_city}.
                    </p>
                    {order.cancelled_at && (
                        <p className="mt-2 text-sm text-muted">
                            Cancelled {formatDateTime(order.cancelled_at)}: {order.cancel_reason}
                        </p>
                    )}
                    {can('orders.manage') && order.status === 'pending_payment' && (
                        <Button variant="secondary" className="mt-4" onClick={() => (reset(), setReason(''), setDialog('cancel'))}>
                            Cancel order
                        </Button>
                    )}
                </Card>
                {a && (
                    <Card title="Attribution">
                        <div className="mb-3">
                            <StatusBadge status={a.status} label={a.status === 'attributed' ? 'Attributed' : a.status === 'blocked' ? 'Blocked' : 'No referral'} />
                        </div>
                        {a.affiliate ? (
                            <dl className="space-y-1 text-sm">
                                <div>
                                    <dt className="inline text-muted">Affiliate: </dt>
                                    <dd className="inline">
                                        {can('affiliates.view') ? (
                                            <Link className="text-brand-600 hover:underline" to={`/admin/affiliates/${a.affiliate.id}`}>
                                                {a.affiliate.display_name}
                                            </Link>
                                        ) : (
                                            a.affiliate.display_name
                                        )}{' '}
                                        ({a.affiliate.referral_code})
                                    </dd>
                                </div>
                                <div>
                                    <dt className="inline text-muted">Source: </dt>
                                    <dd className="inline">{a.source_label}</dd>
                                </div>
                                {a.clicked_at && (
                                    <div>
                                        <dt className="inline text-muted">Clicked: </dt>
                                        <dd className="inline">{formatDateTime(a.clicked_at)}</dd>
                                    </div>
                                )}
                                {a.click && (
                                    <div>
                                        <dt className="inline text-muted">Click: </dt>
                                        <dd className="inline">
                                            {[a.click.link && `link “${a.click.link}”`, a.click.landing_path, a.click.referrer_host && `from ${a.click.referrer_host}`, a.click.utm_campaign && `campaign ${a.click.utm_campaign}`]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </dd>
                                    </div>
                                )}
                                {a.decided_by && (
                                    <div>
                                        <dt className="inline text-muted">Set by: </dt>
                                        <dd className="inline">
                                            {a.decided_by}: {a.reason}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        ) : (
                            <p className="text-sm text-muted">{a.block_reason ? BLOCK_REASONS[a.block_reason] ?? a.block_reason : 'No referral link, code or affiliate coupon was used.'}</p>
                        )}
                        {can('attribution.manual') && !['cancelled', 'refunded', 'partially_refunded'].includes(order.status) && !order.commissions?.length && (
                            <Button variant="secondary" className="mt-4" onClick={openAttribute}>
                                {a.affiliate ? 'Change affiliate' : 'Attribute manually'}
                            </Button>
                        )}
                    </Card>
                )}
            </div>
            <OrderMoneyPanels order={order} onChange={setOrder} />
            <OrderOperationsPanels order={order} onChange={setOrder} reload={load} />
            <Modal title={dialog === 'cancel' ? 'Cancel order' : 'Attribute order manually'} open={dialog !== null} onClose={() => setDialog(null)}>
                <form onSubmit={submit} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    {dialog === 'attribute' && (
                        <Select label="Affiliate" value={affiliateId} onChange={(e) => setAffiliateId(e.target.value)} error={fieldErrors.affiliate_id ?? fieldErrors.order}>
                            <option value="">Choose an approved affiliate</option>
                            {affiliates.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.display_name} ({o.referral_code})
                                </option>
                            ))}
                        </Select>
                    )}
                    <TextArea
                        label="Reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        error={fieldErrors.reason ?? fieldErrors.status}
                        hint={dialog === 'attribute' ? 'Recorded in the audit log, e.g. what evidence the customer showed.' : 'Recorded in the audit log.'}
                    />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setDialog(null)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            {dialog === 'cancel' ? 'Cancel order' : 'Save attribution'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
