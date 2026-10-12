import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Select, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import type { Product } from '@/store/types';

interface Coupon {
    id: string;
    code: string;
    affiliate: { id: string; display_name: string; referral_code: string | null; status: string } | null;
    discount_type: 'percent' | 'fixed';
    discount_value: number;
    min_order_minor: number;
    max_discount_minor: number | null;
    usage_limit: number | null;
    per_customer_limit: number | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    earns_commission: boolean;
    products: { id: string; name: string }[];
    campaign: { id: string; name: string } | null;
    times_used: number;
}

interface AffiliateOption {
    id: string;
    display_name: string;
    referral_code: string | null;
}

const EMPTY = {
    code: '',
    affiliate_id: '',
    discount_type: 'percent',
    amount: '',
    min_order: '',
    max_discount: '',
    usage_limit: '',
    per_customer_limit: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
    campaign_id: '',
    product_ids: [] as string[],
};

const describe = (c: Coupon) => (c.discount_type === 'percent' ? `${c.discount_value / 100}% off` : `${formatMoney(c.discount_value)} off`);

export default function CouponsPage() {
    const { can } = useAuth();
    const [coupons, setCoupons] = useState<Coupon[] | null>(null);
    const [affiliates, setAffiliates] = useState<AffiliateOption[]>([]);
    const [products, setProducts] = useState<Product[]>([]);
    const [campaigns, setCampaigns] = useState<{ id: string; name: string; state: string }[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = () =>
        api<{ data: Coupon[] }>('/admin/coupons')
            .then((r) => setCoupons(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load coupons.'));

    useEffect(() => {
        load();
        api<{ data: Product[] }>('/admin/products').then((r) => setProducts(r.data)).catch(() => undefined);
        api<{ data: { id: string; name: string; state: string }[] }>('/admin/campaigns').then((r) => setCampaigns(r.data)).catch(() => undefined);
        if (can('affiliates.view')) {
            api<{ data: AffiliateOption[] }>('/admin/affiliates?status=approved&per_page=100').then((r) => setAffiliates(r.data)).catch(() => undefined);
        }
    }, [can]);

    const open = (c: Coupon | 'new') => {
        reset();
        setEditing(c);
        setForm(
            c === 'new'
                ? EMPTY
                : {
                      code: c.code,
                      affiliate_id: c.affiliate?.id ?? '',
                      discount_type: c.discount_type,
                      amount: String(c.discount_type === 'percent' ? c.discount_value / 100 : c.discount_value / 100),
                      min_order: c.min_order_minor ? String(c.min_order_minor / 100) : '',
                      max_discount: c.max_discount_minor ? String(c.max_discount_minor / 100) : '',
                      usage_limit: c.usage_limit ? String(c.usage_limit) : '',
                      per_customer_limit: c.per_customer_limit ? String(c.per_customer_limit) : '',
                      starts_at: c.starts_at?.slice(0, 10) ?? '',
                      ends_at: c.ends_at?.slice(0, 10) ?? '',
                      is_active: c.is_active,
                      campaign_id: c.campaign?.id ?? '',
                      product_ids: c.products.map((p) => p.id),
                  },
        );
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const body = {
            code: form.code,
            affiliate_id: form.affiliate_id || null,
            discount_type: form.discount_type,
            // Percent is stored in basis points (10% = 1000); fixed in paisa.
            discount_value: form.amount ? Math.round(Number(form.amount) * 100) : null,
            min_order_minor: form.min_order ? toMinor(form.min_order) : 0,
            max_discount_minor: form.max_discount ? toMinor(form.max_discount) : null,
            usage_limit: form.usage_limit ? Number(form.usage_limit) : null,
            per_customer_limit: form.per_customer_limit ? Number(form.per_customer_limit) : null,
            starts_at: form.starts_at || null,
            ends_at: form.ends_at ? `${form.ends_at}T23:59:59+05:45` : null,
            is_active: form.is_active,
            campaign_id: form.campaign_id || null,
            product_ids: form.product_ids,
        };
        try {
            await api(editing === 'new' ? '/admin/coupons' : `/admin/coupons/${(editing as Coupon).id}`, { method: editing === 'new' ? 'POST' : 'PATCH', body });
            setEditing(null);
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!coupons) return <Spinner />;
    const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Coupons</h1>
                <Button onClick={() => open('new')}>New coupon</Button>
            </div>
            <p className="text-sm text-muted">
                An affiliate coupon credits its order to that affiliate, even if the customer clicked someone else’s link first. One coupon per order.
            </p>
            <Card>
                {coupons.length === 0 ? (
                    <p className="text-sm text-muted">No coupons yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {coupons.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                <div>
                                    <p className="font-semibold text-ink">
                                        {c.code} <span className="font-normal text-muted">· {describe(c)}</span>
                                        {!c.is_active && <span className="ml-2 text-xs text-muted">(inactive)</span>}
                                    </p>
                                    <p className="text-muted">
                                        {c.affiliate ? `Affiliate: ${c.affiliate.display_name}` : 'Store-wide'} · used {c.times_used}
                                        {c.usage_limit ? ` of ${c.usage_limit}` : ''} times
                                        {c.ends_at ? ` · ends ${formatDate(c.ends_at)}` : ''}
                                        {c.products.length ? ` · ${c.products.length} product(s)` : ''}
                                        {c.campaign ? ` · campaign: ${c.campaign.name}` : ''}
                                    </p>
                                </div>
                                <button type="button" className="text-brand-600 hover:underline" onClick={() => open(c)}>
                                    Edit
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Modal title={editing === 'new' ? 'New coupon' : 'Edit coupon'} open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={save} className="max-h-[70vh] space-y-3 overflow-y-auto pr-1">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Code" value={form.code} onChange={set('code')} error={fieldErrors.code} hint="Letters, numbers, - and _. Cannot change once used." />
                    <Select label="Affiliate" value={form.affiliate_id} onChange={set('affiliate_id')} error={fieldErrors.affiliate_id}>
                        <option value="">None (store-wide coupon)</option>
                        {affiliates.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.display_name} ({a.referral_code})
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Campaign"
                        value={form.campaign_id}
                        onChange={set('campaign_id')}
                        error={fieldErrors.campaign_id}
                    >
                        <option value="">None</option>
                        {campaigns
                            .filter((k) => k.state !== 'ended' || k.id === form.campaign_id)
                            .map((k) => (
                                <option key={k.id} value={k.id}>
                                    {k.name}
                                </option>
                            ))}
                    </Select>
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Type" value={form.discount_type} onChange={set('discount_type')} error={fieldErrors.discount_type}>
                            <option value="percent">Percentage</option>
                            <option value="fixed">Fixed amount</option>
                        </Select>
                        <Field
                            label={form.discount_type === 'percent' ? 'Percent off' : 'Amount off (NPR)'}
                            type="number"
                            min="0"
                            step="0.01"
                            value={form.amount}
                            onChange={set('amount')}
                            error={fieldErrors.discount_value}
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Minimum order (NPR)" type="number" min="0" value={form.min_order} onChange={set('min_order')} error={fieldErrors.min_order_minor} />
                        <Field label="Maximum discount (NPR)" type="number" min="0" value={form.max_discount} onChange={set('max_discount')} error={fieldErrors.max_discount_minor} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Total uses" type="number" min="1" value={form.usage_limit} onChange={set('usage_limit')} error={fieldErrors.usage_limit} hint="Blank = unlimited" />
                        <Field label="Uses per customer" type="number" min="1" value={form.per_customer_limit} onChange={set('per_customer_limit')} error={fieldErrors.per_customer_limit} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Starts" type="date" value={form.starts_at} onChange={set('starts_at')} error={fieldErrors.starts_at} />
                        <Field label="Ends" type="date" value={form.ends_at} onChange={set('ends_at')} error={fieldErrors.ends_at} />
                    </div>
                    <fieldset className="space-y-1">
                        <legend className="text-sm font-medium text-ink">Applies to (none ticked = all products)</legend>
                        <div className="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                            {products.map((p) => (
                                <label key={p.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.product_ids.includes(p.id)}
                                        onChange={(e) =>
                                            setForm((f) => ({ ...f, product_ids: e.target.checked ? [...f.product_ids, p.id] : f.product_ids.filter((id) => id !== p.id) }))
                                        }
                                    />
                                    {p.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                        Active
                    </label>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>
                            Cancel
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
