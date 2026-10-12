import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { rateLabel } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import type { Product } from '@/store/types';

interface Campaign {
    id: string;
    name: string;
    description: string | null;
    starts_at: string;
    ends_at: string | null;
    state: 'running' | 'upcoming' | 'ended';
    is_active: boolean;
    budget_minor: number | null;
    currency: string;
    all_affiliates: boolean;
    affiliates: { id: string; display_name: string; referral_code: string | null }[];
    products: { id: string; name: string }[];
    rule: { id: string; name: string; kind: string; rate_bp: number | null; fixed_minor: number | null } | null;
    stats: { orders: number; sales_minor: number; commission_minor: number };
}

interface AffiliateOption {
    id: string;
    display_name: string;
    referral_code: string | null;
}

const EMPTY = {
    name: '',
    description: '',
    starts_at: '',
    ends_at: '',
    budget: '',
    is_active: true,
    all_affiliates: true,
    affiliate_ids: [] as string[],
    product_ids: [] as string[],
};

const STATE_LABELS: Record<Campaign['state'], string> = { running: 'Running', upcoming: 'Upcoming', ended: 'Ended' };

export default function CampaignsPage() {
    const { can } = useAuth();
    const [campaigns, setCampaigns] = useState<Campaign[] | null>(null);
    const [affiliates, setAffiliates] = useState<AffiliateOption[]>([]);
    const [products, setProducts] = useState<Product[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState<Campaign | 'new' | null>(null);
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(
        () =>
            api<{ data: Campaign[] }>('/admin/campaigns')
                .then((r) => setCampaigns(r.data))
                .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load campaigns.')),
        [],
    );

    useEffect(() => {
        load();
        api<{ data: Product[] }>('/admin/products').then((r) => setProducts(r.data)).catch(() => undefined);
        if (can('affiliates.view')) {
            api<{ data: AffiliateOption[] }>('/admin/affiliates?status=approved&per_page=100').then((r) => setAffiliates(r.data)).catch(() => undefined);
        }
    }, [can, load]);

    const open = (c: Campaign | 'new') => {
        reset();
        setEditing(c);
        setForm(
            c === 'new'
                ? EMPTY
                : {
                      name: c.name,
                      description: c.description ?? '',
                      starts_at: c.starts_at.slice(0, 10),
                      ends_at: c.ends_at?.slice(0, 10) ?? '',
                      budget: c.budget_minor ? String(c.budget_minor / 100) : '',
                      is_active: c.is_active,
                      all_affiliates: c.all_affiliates,
                      affiliate_ids: c.affiliates.map((a) => a.id),
                      product_ids: c.products.map((p) => p.id),
                  },
        );
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        // Dates are whole days in Nepal time: from the start of the first day to the end of the last.
        const body = {
            name: form.name,
            description: form.description || null,
            starts_at: form.starts_at ? `${form.starts_at}T00:00:00+05:45` : null,
            ends_at: form.ends_at ? `${form.ends_at}T23:59:59+05:45` : null,
            budget_minor: form.budget ? toMinor(form.budget) : null,
            is_active: form.is_active,
            all_affiliates: form.all_affiliates,
            affiliate_ids: form.all_affiliates ? [] : form.affiliate_ids,
            product_ids: form.product_ids,
        };
        try {
            await api(editing === 'new' ? '/admin/campaigns' : `/admin/campaigns/${(editing as Campaign).id}`, { method: editing === 'new' ? 'POST' : 'PATCH', body });
            setEditing(null);
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!campaigns) return <Spinner />;
    const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));
    const toggle = (key: 'affiliate_ids' | 'product_ids', id: string, on: boolean) =>
        setForm((f) => ({ ...f, [key]: on ? [...f[key], id] : f[key].filter((x) => x !== id) }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Campaigns</h1>
                <Button onClick={() => open('new')}>New campaign</Button>
            </div>
            <p className="text-sm text-muted">
                A campaign runs for set dates on chosen products and affiliates. Orders count for it when they come through a campaign tracking link or a coupon
                linked to it. Its commission comes from a rule for the campaign in{' '}
                <Link to="/admin/commission-rules" className="text-brand-600 hover:underline">
                    Commission rules
                </Link>
                ; that rule beats affiliate overrides, but product and category exclusions still apply.
            </p>
            <Card>
                {campaigns.length === 0 ? (
                    <p className="text-sm text-muted">No campaigns yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {campaigns.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                                <div className="min-w-0 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2 font-semibold text-ink">
                                        {c.name} <StatusBadge status={c.state} label={STATE_LABELS[c.state]} />
                                    </p>
                                    <p className="text-muted">
                                        {formatDate(c.starts_at)} to {c.ends_at ? formatDate(c.ends_at) : 'no end date'} ·{' '}
                                        {c.all_affiliates ? 'all affiliates' : `${c.affiliates.length} affiliate(s)`} ·{' '}
                                        {c.products.length ? `${c.products.length} product(s)` : 'all products'}
                                    </p>
                                    <p className="text-muted">
                                        Commission: {c.rule ? rateLabel(c.rule.kind, c.rule.rate_bp, c.rule.fixed_minor, c.currency) : 'no campaign rule in force (normal rates apply)'}
                                    </p>
                                    <p className="text-ink">
                                        {c.stats.orders} paid order(s) · sales {formatMoney(c.stats.sales_minor)} · commission {formatMoney(c.stats.commission_minor)}
                                        {c.budget_minor ? ` of ${formatMoney(c.budget_minor)} budget` : ''}
                                    </p>
                                    {c.budget_minor !== null && c.stats.commission_minor > c.budget_minor && (
                                        <p className="text-danger">Commission is over the budget. The budget does not stop commission; end the campaign if needed.</p>
                                    )}
                                </div>
                                <button type="button" className="text-brand-600 hover:underline" onClick={() => open(c)}>
                                    Edit
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Modal title={editing === 'new' ? 'New campaign' : 'Edit campaign'} open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={save} className="max-h-[70vh] space-y-3 overflow-y-auto pr-1">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Name" value={form.name} onChange={set('name')} error={fieldErrors.name} hint="Affiliates see this name." />
                    <TextArea
                        label="Description (optional)"
                        value={form.description}
                        onChange={set('description')}
                        error={fieldErrors.description}
                        hint="What to promote and how. Affiliates see this."
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="First day" type="date" value={form.starts_at} onChange={set('starts_at')} error={fieldErrors.starts_at} />
                        <Field label="Last day (optional)" type="date" value={form.ends_at} onChange={set('ends_at')} error={fieldErrors.ends_at} />
                    </div>
                    <Field
                        label="Commission budget (NPR, optional)"
                        type="number"
                        min="0"
                        value={form.budget}
                        onChange={set('budget')}
                        error={fieldErrors.budget_minor}
                        hint="For tracking only. Commission is not stopped when it is reached."
                    />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.all_affiliates} onChange={(e) => setForm((f) => ({ ...f, all_affiliates: e.target.checked }))} />
                        Open to all affiliates
                    </label>
                    {!form.all_affiliates && (
                        <fieldset className="space-y-1">
                            <legend className="text-sm font-medium text-ink">Affiliates taking part</legend>
                            <div className="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                                {affiliates.map((a) => (
                                    <label key={a.id} className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.affiliate_ids.includes(a.id)} onChange={(e) => toggle('affiliate_ids', a.id, e.target.checked)} />
                                        {a.display_name} ({a.referral_code})
                                    </label>
                                ))}
                            </div>
                            {fieldErrors.affiliate_ids && <p className="text-sm text-danger">{fieldErrors.affiliate_ids}</p>}
                        </fieldset>
                    )}
                    <fieldset className="space-y-1">
                        <legend className="text-sm font-medium text-ink">Products (none ticked = all products)</legend>
                        <div className="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                            {products.map((p) => (
                                <label key={p.id} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.product_ids.includes(p.id)} onChange={(e) => toggle('product_ids', p.id, e.target.checked)} />
                                    {p.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                        Active (untick to end the campaign now)
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
