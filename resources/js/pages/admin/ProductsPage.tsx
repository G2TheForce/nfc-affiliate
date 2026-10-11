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
        const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        await api('/admin/categories', { method: 'POST', body: { name, slug } }).catch(() => undefined);
        setNewCategory('');
        await load();
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
                                            <span className="font-medium text-ink">{p.name}</span>
                                            {p.category && <span className="block text-xs text-muted">{p.category.name}</span>}
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
                <p className="text-sm text-muted">{categories.map((c) => c.name).join(', ') || 'None yet.'}</p>
                <form onSubmit={addCategory} className="mt-3 flex gap-2">
                    <label htmlFor="new-cat" className="sr-only">
                        New category
                    </label>
                    <input id="new-cat" value={newCategory} onChange={(e) => setNewCategory(e.target.value)} placeholder="New category" className="min-h-11 flex-1 rounded-lg border border-line px-3 text-base sm:text-sm" />
                    <Button type="submit" variant="secondary">
                        Add
                    </Button>
                </form>
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
