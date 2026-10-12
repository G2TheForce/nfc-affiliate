import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Card, Field, Modal, Select, Spinner, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import type { Product } from '@/store/types';

interface Category {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    products_count: number;
}

const EMPTY = { name: '', sku: '', slug: '', description: '', price: '', category_id: '', is_active: true };

export default function ProductsPage() {
    const [products, setProducts] = useState<Product[] | null>(null);
    const [categories, setCategories] = useState<Category[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState<Product | 'new' | null>(null);
    const [form, setForm] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const [newCategory, setNewCategory] = useState('');
    const [categoryError, setCategoryError] = useState<string | null>(null);
    const [imageError, setImageError] = useState<string | null>(null);
    const [uploading, setUploading] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = () =>
        Promise.all([api<{ data: Product[] }>('/admin/products'), api<{ data: Category[] }>('/admin/categories')])
            .then(([p, c]) => (setProducts(p.data), setCategories(c.data)))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load products.'));

    useEffect(() => {
        load();
    }, []);

    const open = (p: Product | 'new') => {
        reset();
        setImageError(null);
        setEditing(p);
        setForm(
            p === 'new'
                ? EMPTY
                : {
                      name: p.name,
                      sku: p.sku,
                      slug: p.slug,
                      description: p.description ?? '',
                      price: String(p.price_minor / 100),
                      category_id: p.category ? String(p.category.id) : '',
                      is_active: p.is_active ?? true,
                  },
        );
    };

    const save = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const body = {
            name: form.name,
            sku: form.sku,
            slug: form.slug,
            description: form.description || null,
            price_minor: form.price ? toMinor(form.price) : null,
            category_id: form.category_id ? Number(form.category_id) : null,
            is_active: form.is_active,
        };
        try {
            await api(editing === 'new' ? '/admin/products' : `/admin/products/${(editing as Product).id}`, { method: editing === 'new' ? 'POST' : 'PATCH', body });
            setEditing(null);
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const addCategory = async (e: FormEvent) => {
        e.preventDefault();
        const name = newCategory.trim();
        if (!name) return;
        // A name in Nepali script has no Latin letters for the web address, so it gets a generated one.
        const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `category-${Date.now().toString(36)}`;
        setCategoryError(null);
        try {
            await api('/admin/categories', { method: 'POST', body: { name, slug } });
            setNewCategory('');
            await load();
        } catch (err) {
            const first = err instanceof ApiError ? Object.values(err.errors)[0]?.[0] : undefined;
            setCategoryError(first ?? (err instanceof ApiError ? err.message : 'Could not add the category.'));
        }
    };

    const uploadImage = async (product: Product, file: File | null) => {
        if (!file) return;
        setImageError(null);
        setUploading(true);
        const body = new FormData();
        body.append('image', file);
        try {
            const r = await api<{ data: Product }>(`/admin/products/${product.id}/image`, { method: 'POST', body });
            setEditing(r.data);
            await load();
        } catch (err) {
            const first = err instanceof ApiError ? Object.values(err.errors)[0]?.[0] : undefined;
            setImageError(first ?? (err instanceof ApiError ? err.message : 'The photo could not be uploaded.'));
        } finally {
            setUploading(false);
        }
    };

    const removeImage = async (product: Product) => {
        setImageError(null);
        try {
            const r = await api<{ data: Product }>(`/admin/products/${product.id}/image`, { method: 'DELETE' });
            setEditing(r.data);
            await load();
        } catch (err) {
            setImageError(err instanceof ApiError ? err.message : 'The photo could not be removed.');
        }
    };

    const renameCategory = async (c: Category) => {
        const name = window.prompt('New name for this category', c.name)?.trim();
        if (!name || name === c.name) return;
        await categoryAction(() => api(`/admin/categories/${c.id}`, { method: 'PATCH', body: { name } }));
    };

    const categoryAction = async (run: () => Promise<unknown>) => {
        setCategoryError(null);
        try {
            await run();
            await load();
        } catch (err) {
            const first = err instanceof ApiError ? Object.values(err.errors)[0]?.[0] : undefined;
            setCategoryError(first ?? (err instanceof ApiError ? err.message : 'That did not work. Please try again.'));
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!products) return <Spinner />;
    const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Products</h1>
                <Button onClick={() => open('new')}>New product</Button>
            </div>
            <Card>
                {products.length === 0 ? (
                    <p className="text-sm text-muted">No products yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th className="py-2 font-medium">Product</th>
                                    <th className="py-2 font-medium">SKU</th>
                                    <th className="py-2 text-right font-medium">Price</th>
                                    <th className="py-2 font-medium">Status</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {products.map((p) => (
                                    <tr key={p.id}>
                                        <td className="py-2">
                                            <div className="flex items-center gap-3">
                                                {p.image_url ? (
                                                    <img src={p.image_url} alt="" className="size-10 rounded-md bg-canvas object-cover" />
                                                ) : (
                                                    <span className="size-10 rounded-md bg-canvas" aria-hidden />
                                                )}
                                                <div>
                                                    <span className="font-medium text-ink">{p.name}</span>
                                                    {p.category && <span className="block text-xs text-muted">{p.category.name}</span>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="py-2 text-muted">{p.sku}</td>
                                        <td className="py-2 text-right tabular-nums">{formatMoney(p.price_minor, p.currency)}</td>
                                        <td className="py-2">{p.is_active ? 'Active' : 'Hidden'}</td>
                                        <td className="py-2 text-right">
                                            <button type="button" className="text-brand-600 hover:underline" onClick={() => open(p)}>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            <Card title="Categories">
                {categories.length === 0 ? (
                    <p className="text-sm text-muted">None yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {categories.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <span className="font-medium text-ink">{c.name}</span>
                                    <span className="text-muted">
                                        {' '}
                                        · {c.products_count} product(s){c.is_active ? '' : ' · hidden from the shop'}
                                    </span>
                                </span>
                                <span className="flex gap-3">
                                    <button type="button" className="text-brand-600 hover:underline" onClick={() => renameCategory(c)}>
                                        Rename
                                    </button>
                                    <button
                                        type="button"
                                        className="text-brand-600 hover:underline"
                                        onClick={() => categoryAction(() => api(`/admin/categories/${c.id}`, { method: 'PATCH', body: { is_active: !c.is_active } }))}
                                    >
                                        {c.is_active ? 'Hide' : 'Show'}
                                    </button>
                                    {c.products_count === 0 && (
                                        <button
                                            type="button"
                                            className="text-danger hover:underline"
                                            onClick={() =>
                                                window.confirm(`Delete the category “${c.name}”?`) && categoryAction(() => api(`/admin/categories/${c.id}`, { method: 'DELETE' }))
                                            }
                                        >
                                            Delete
                                        </button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <form onSubmit={addCategory} className="mt-3 flex gap-2">
                    <label htmlFor="new-cat" className="sr-only">
                        New category
                    </label>
                    <input id="new-cat" value={newCategory} onChange={(e) => setNewCategory(e.target.value)} placeholder="New category" className="min-h-11 flex-1 rounded-lg border border-line px-3 text-base sm:text-sm" />
                    <Button type="submit" variant="secondary">
                        Add
                    </Button>
                </form>
                {categoryError && (
                    <div className="mt-3">
                        <Alert tone="error">{categoryError}</Alert>
                    </div>
                )}
            </Card>
            <Modal title={editing === 'new' ? 'New product' : 'Edit product'} open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={save} className="max-h-[70vh] space-y-3 overflow-y-auto">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Name" value={form.name} onChange={set('name')} error={fieldErrors.name} />
                    <Field label="SKU" value={form.sku} onChange={set('sku')} error={fieldErrors.sku} />
                    <Field label="URL slug" value={form.slug} onChange={set('slug')} error={fieldErrors.slug} hint="Lower-case letters, numbers and hyphens, e.g. metal-nfc-card" />
                    <Field label="Price (NPR)" type="number" min="0" step="0.01" value={form.price} onChange={set('price')} error={fieldErrors.price_minor} />
                    <Select label="Category" value={form.category_id} onChange={set('category_id')} error={fieldErrors.category_id}>
                        <option value="">None</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </Select>
                    {editing && editing !== 'new' && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium text-ink">Photo</p>
                            {editing.image_url && <img src={editing.image_url} alt="" className="h-32 rounded-lg bg-canvas object-contain" />}
                            <div className="flex flex-wrap items-center gap-3">
                                <label className="cursor-pointer text-sm text-brand-600 hover:underline">
                                    {uploading ? 'Uploading…' : editing.image_url ? 'Replace photo' : 'Add photo'}
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        className="sr-only"
                                        disabled={uploading}
                                        onChange={(e) => uploadImage(editing, e.target.files?.[0] ?? null)}
                                    />
                                </label>
                                {editing.image_url && (
                                    <button type="button" className="text-sm text-danger hover:underline" onClick={() => removeImage(editing)}>
                                        Remove photo
                                    </button>
                                )}
                            </div>
                            <p className="text-xs text-muted">JPG, PNG or WebP up to 5 MB. A square photo about 1200 pixels wide looks best.</p>
                            {imageError && <p className="text-sm text-danger">{imageError}</p>}
                        </div>
                    )}
                    {editing === 'new' && <p className="text-sm text-muted">Add a photo after saving the product.</p>}
                    <TextArea label="Description" value={form.description} onChange={set('description')} error={fieldErrors.description} />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                        Visible in the shop
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
