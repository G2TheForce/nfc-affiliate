import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Card, Field, Modal, Select, Spinner, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';
import { AssetCard, type MarketingAsset } from '@/marketing/AssetCard';
import type { Product } from '@/store/types';

const EMPTY = { title: '', description: '', caption: '', campaign_id: '', product_id: '', is_active: true };

export default function MarketingPage() {
    const [assets, setAssets] = useState<MarketingAsset[] | null>(null);
    const [products, setProducts] = useState<Product[]>([]);
    const [campaigns, setCampaigns] = useState<{ id: string; name: string; state: string }[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState<MarketingAsset | 'new' | null>(null);
    const [form, setForm] = useState(EMPTY);
    const [file, setFile] = useState<File | null>(null);
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(
        () =>
            api<{ data: MarketingAsset[] }>('/admin/marketing-assets')
                .then((r) => setAssets(r.data))
                .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load marketing materials.')),
        [],
    );

    useEffect(() => {
        load();
        api<{ data: Product[] }>('/admin/products').then((r) => setProducts(r.data)).catch(() => undefined);
        api<{ data: { id: string; name: string; state: string }[] }>('/admin/campaigns').then((r) => setCampaigns(r.data)).catch(() => undefined);
    }, [load]);

    const open = (a: MarketingAsset | 'new') => {
        reset();
        setFile(null);
        setEditing(a);
        setForm(
            a === 'new'
                ? EMPTY
                : {
                      title: a.title,
                      description: a.description ?? '',
                      caption: a.caption ?? '',
                      campaign_id: a.campaign?.id ?? '',
                      product_id: a.product?.id ?? '',
                      is_active: a.is_active ?? true,
                  },
        );
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        try {
            if (editing === 'new') {
                const body = new FormData();
                body.append('title', form.title);
                if (form.description) body.append('description', form.description);
                if (form.caption) body.append('caption', form.caption);
                if (form.campaign_id) body.append('campaign_id', form.campaign_id);
                if (form.product_id) body.append('product_id', form.product_id);
                body.append('is_active', form.is_active ? '1' : '0');
                if (file) body.append('file', file);
                await api('/admin/marketing-assets', { method: 'POST', body });
            } else {
                await api(`/admin/marketing-assets/${(editing as MarketingAsset).id}`, {
                    method: 'PATCH',
                    body: {
                        title: form.title,
                        description: form.description || null,
                        caption: form.caption || null,
                        campaign_id: form.campaign_id || null,
                        product_id: form.product_id || null,
                        is_active: form.is_active,
                    },
                });
            }
            setEditing(null);
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const remove = async (a: MarketingAsset) => {
        if (!window.confirm(`Delete “${a.title}”? Affiliates will no longer see it, and the file is removed.`)) return;
        try {
            await api(`/admin/marketing-assets/${a.id}`, { method: 'DELETE' });
            await load();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'Could not delete it.');
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!assets) return <Spinner />;
    const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Marketing materials</h1>
                <Button onClick={() => open('new')}>Add material</Button>
            </div>
            <p className="text-sm text-muted">
                Banners, photos, videos, PDFs, ZIP media kits and ready-to-post text for affiliates. Materials tied to a campaign are shown only while it runs, and
                only to affiliates taking part.
            </p>
            {assets.length === 0 ? (
                <Card>
                    <p className="text-sm text-muted">Nothing yet. Add your first banner or post text.</p>
                </Card>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {assets.map((a) => (
                        <AssetCard
                            key={a.id}
                            asset={a}
                            filePath={`/admin/marketing-assets/${a.id}/file`}
                            actions={
                                <>
                                    <span className="text-xs text-muted">
                                        {a.is_active ? '' : 'Hidden · '}
                                        {a.download_count ?? 0} download(s)
                                    </span>
                                    <button type="button" className="text-sm text-brand-600 hover:underline" onClick={() => open(a)}>
                                        Edit
                                    </button>
                                    <button type="button" className="text-sm text-danger hover:underline" onClick={() => remove(a)}>
                                        Delete
                                    </button>
                                </>
                            }
                        />
                    ))}
                </ul>
            )}
            <Modal title={editing === 'new' ? 'Add material' : 'Edit material'} open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={save} className="max-h-[70vh] space-y-3 overflow-y-auto pr-1">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Title" value={form.title} onChange={set('title')} error={fieldErrors.title} hint="Affiliates see this. It also names the downloaded file." />
                    {editing === 'new' ? (
                        <div className="space-y-1">
                            <label htmlFor="asset-file" className="block text-sm font-medium text-ink">
                                File (optional for post text)
                            </label>
                            <input
                                id="asset-file"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,application/pdf,application/zip,.zip"
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                className="block w-full text-sm"
                            />
                            <p className="text-xs text-muted">JPG, PNG, WebP, GIF, MP4, PDF or ZIP, up to 20 MB.</p>
                            {fieldErrors.file && <p className="text-sm text-danger">{fieldErrors.file}</p>}
                        </div>
                    ) : (
                        <p className="text-sm text-muted">To change the file, add a new material and delete this one.</p>
                    )}
                    <TextArea label="Description (optional)" value={form.description} onChange={set('description')} error={fieldErrors.description} hint="Where and how to use it." />
                    <TextArea label="Post text (optional)" value={form.caption} onChange={set('caption')} error={fieldErrors.caption} hint="Affiliates can copy it with one tap." />
                    <Select label="Campaign" value={form.campaign_id} onChange={set('campaign_id')} error={fieldErrors.campaign_id}>
                        <option value="">None (always shown)</option>
                        {campaigns
                            .filter((k) => k.state !== 'ended' || k.id === form.campaign_id)
                            .map((k) => (
                                <option key={k.id} value={k.id}>
                                    {k.name}
                                </option>
                            ))}
                    </Select>
                    <Select label="Product" value={form.product_id} onChange={set('product_id')} error={fieldErrors.product_id}>
                        <option value="">None</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </Select>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                        Shown to affiliates
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
