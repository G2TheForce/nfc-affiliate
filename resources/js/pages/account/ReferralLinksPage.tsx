import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Card, Field, Modal, Select, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';
import type { Product } from '@/store/types';

interface LinkRow {
    id: string;
    name: string;
    slug: string;
    url: string;
    product: { id: string; name: string; slug: string } | null;
    utm_source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    is_active: boolean;
}

interface LinksData {
    referral_code: string;
    main_url: string;
    can_create: boolean;
    attribution: { policy: string; window_days: number; coupon_overrides_click: boolean };
    links: LinkRow[];
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <Button
            variant="secondary"
            type="button"
            onClick={() => navigator.clipboard?.writeText(text).then(() => (setCopied(true), setTimeout(() => setCopied(false), 1500)))}
        >
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

const EMPTY = { name: '', slug: '', product_id: '', utm_source: '', utm_medium: '', utm_campaign: '' };

export default function ReferralLinksPage() {
    const [data, setData] = useState<LinksData | null>(null);
    const [products, setProducts] = useState<Product[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = () =>
        api<{ data: LinksData }>('/affiliate/links')
            .then((r) => setData(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load your links.'));

    useEffect(() => {
        load();
        api<{ data: Product[] }>('/store/products').then((r) => setProducts(r.data)).catch(() => undefined);
    }, []);

    const create = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        try {
            await api('/affiliate/links', {
                method: 'POST',
                body: Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null])),
            });
            setOpen(false);
            setForm(EMPTY);
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const toggle = async (link: LinkRow) => {
        await api(`/affiliate/links/${link.id}`, { method: 'PATCH', body: { is_active: !link.is_active } }).catch(() => undefined);
        await load();
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Referral links</h1>
            <Card title="Your main link">
                <div className="flex flex-wrap items-center gap-2">
                    <code className="min-w-0 flex-1 break-all rounded-lg bg-canvas px-3 py-2 text-sm">{data.main_url}</code>
                    <CopyButton text={data.main_url} />
                </div>
                <p className="mt-3 text-sm text-muted">
                    Your referral code is <strong className="text-ink">{data.referral_code}</strong>. Customers can also type it at checkout.
                </p>
                <p className="mt-2 text-sm text-muted">
                    How orders are credited: the most recent referral link a customer clicked in the last {data.attribution.window_days} days
                    {data.attribution.coupon_overrides_click ? ', unless they use an affiliate discount code, which takes priority' : ''}. Your own purchases are
                    not credited. A placed order is not a sale until it is paid.
                </p>
            </Card>

            <Card title="Tracking links">
                <p className="mb-3 text-sm text-muted">Create a link per product or campaign to see which posts bring orders.</p>
                {data.can_create ? (
                    <Button onClick={() => setOpen(true)}>New tracking link</Button>
                ) : (
                    <Alert>Your membership is not active, so you cannot create new links.</Alert>
                )}
                {data.links.length > 0 && (
                    <ul className="mt-4 divide-y divide-line">
                        {data.links.map((l) => (
                            <li key={l.id} className="space-y-2 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-medium text-ink">
                                        {l.name} {!l.is_active && <span className="text-xs text-muted">(paused)</span>}
                                    </p>
                                    {data.can_create && (
                                        <button type="button" onClick={() => toggle(l)} className="text-sm text-brand-600 hover:underline">
                                            {l.is_active ? 'Pause' : 'Resume'}
                                        </button>
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <code className="min-w-0 flex-1 break-all rounded-lg bg-canvas px-3 py-2 text-sm">{l.url}</code>
                                    <CopyButton text={l.url} />
                                </div>
                                <p className="text-xs text-muted">
                                    Opens {l.product ? l.product.name : 'the store home page'}
                                    {l.utm_campaign ? ` · campaign ${l.utm_campaign}` : ''}
                                    {l.utm_source ? ` · source ${l.utm_source}` : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal title="New tracking link" open={open} onClose={() => setOpen(false)}>
                <form onSubmit={create} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Name" value={form.name} onChange={set('name')} error={fieldErrors.name} hint="Only you see this, e.g. “Instagram bio”." />
                    <Field
                        label="Link address"
                        value={form.slug}
                        onChange={set('slug')}
                        error={fieldErrors.slug}
                        hint={`Becomes …/ref/${data.referral_code}/${form.slug.toLowerCase() || 'your-text'}. Cannot be changed later.`}
                    />
                    <Select label="Opens product (optional)" value={form.product_id} onChange={set('product_id')} error={fieldErrors.product_id}>
                        <option value="">Store home page</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                    <Field label="Campaign (optional)" value={form.utm_campaign} onChange={set('utm_campaign')} error={fieldErrors.utm_campaign} />
                    <Field label="Source (optional)" value={form.utm_source} onChange={set('utm_source')} error={fieldErrors.utm_source} hint="e.g. instagram, tiktok" />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving}>
                            Create link
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
