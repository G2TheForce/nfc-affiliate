import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Modal, Select, Spinner, StatusBadge } from '@/components/ui';
import { rateLabel } from '@/commissions/types';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, toMinor } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Rule {
    id: string;
    name: string;
    scope: string;
    scope_label: string;
    target: { id: string; name: string } | null;
    kind: string;
    rate_bp: number | null;
    fixed_minor: number | null;
    currency: string | null;
    starts_at: string;
    ends_at: string | null;
    is_active: boolean;
    version: number;
}

interface Plan {
    id: string;
    name: string;
    description: string | null;
    affiliates_count: number;
}

interface Option {
    id: string;
    name: string;
}

const SCOPES = [
    { value: 'global', label: 'Everyone (global default)' },
    { value: 'plan', label: 'A commission plan' },
    { value: 'category', label: 'A product category' },
    { value: 'product', label: 'A product' },
    { value: 'affiliate', label: 'One affiliate (override)' },
];

const PRECEDENCE = ['Product or category exclusion', 'Affiliate override', 'Product rate', 'Category rate', 'Plan default', 'Global default'];

const toLocalInput = (iso: string | null) => {
    if (!iso) return '';
    const d = new Date(iso);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};
const fromLocalInput = (value: string) => (value ? new Date(value).toISOString() : null);

interface FormState {
    name: string;
    scope: string;
    target_id: string;
    kind: string;
    rate: string;
    fixed: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
}

const blank = (): FormState => ({ name: '', scope: 'global', target_id: '', kind: 'percent', rate: '', fixed: '', starts_at: toLocalInput(new Date().toISOString()), ends_at: '', is_active: true });

export default function CommissionRulesPage() {
    const { can } = useAuth();
    const canManage = can('commission_rules.manage');
    const [rules, setRules] = useState<Rule[] | null>(null);
    const [plans, setPlans] = useState<Plan[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [form, setForm] = useState<FormState>(blank());
    const [targets, setTargets] = useState<Option[]>([]);
    const [planName, setPlanName] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const planErrors = useFormErrors();

    const load = useCallback(() => {
        api<{ data: Rule[] }>('/admin/commission-rules')
            .then((r) => setRules(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load commission rules.'));
        api<{ data: Plan[] }>('/admin/commission-plans').then((r) => setPlans(r.data)).catch(() => undefined);
    }, []);

    useEffect(load, [load]);

    useEffect(() => {
        if (editing !== 'new') return;
        const sources: Record<string, () => Promise<Option[]>> = {
            plan: async () => plans.map((p) => ({ id: p.id, name: p.name })),
            category: async () => (await api<{ data: { name: string; slug: string }[] }>('/store/categories')).data.map((c) => ({ id: c.slug, name: c.name })),
            product: async () => (await api<{ data: { id: string; name: string; sku: string }[] }>('/admin/products')).data.map((p) => ({ id: p.id, name: `${p.name} (${p.sku})` })),
            affiliate: async () =>
                (await api<{ data: { id: string; display_name: string; referral_code: string | null }[] }>('/admin/affiliates?status=approved&per_page=100')).data.map((a) => ({
                    id: a.id,
                    name: `${a.display_name} (${a.referral_code})`,
                })),
        };
        const source = sources[form.scope];
        if (!source) return;
        let active = true;
        source()
            .then((options) => active && setTargets(options))
            .catch(() => active && setTargets([]));
        return () => {
            active = false;
        };
    }, [editing, form.scope, plans]);

    const open = (rule: Rule | 'new') => {
        reset();
        setTargets([]);
        setEditing(rule);
        setForm(
            rule === 'new'
                ? blank()
                : {
                      name: rule.name,
                      scope: rule.scope,
                      target_id: rule.target?.id ?? '',
                      kind: rule.kind,
                      rate: rule.rate_bp !== null ? String(rule.rate_bp / 100) : '',
                      fixed: rule.fixed_minor !== null ? String(rule.fixed_minor / 100) : '',
                      starts_at: toLocalInput(rule.starts_at),
                      ends_at: toLocalInput(rule.ends_at),
                      is_active: rule.is_active,
                  },
        );
    };

    const set = (patch: Partial<FormState>) => setForm((f) => ({ ...f, ...patch }));

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        const body: Record<string, unknown> = {
            name: form.name,
            kind: form.kind,
            rate_bp: form.kind === 'percent' && form.rate ? Math.round(Number(form.rate) * 100) : null,
            fixed_minor: form.kind === 'fixed' && form.fixed ? toMinor(form.fixed) : null,
            starts_at: fromLocalInput(form.starts_at),
            ends_at: fromLocalInput(form.ends_at),
            is_active: form.is_active,
        };
        if (editing === 'new') {
            body.scope = form.scope;
            body.target_id = form.scope === 'global' ? null : form.target_id || null;
        }
        try {
            if (editing === 'new') await api('/admin/commission-rules', { method: 'POST', body });
            else if (editing) await api(`/admin/commission-rules/${editing.id}`, { method: 'PATCH', body });
            setEditing(null);
            load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const addPlan = async (e: FormEvent) => {
        e.preventDefault();
        planErrors.reset();
        try {
            await api('/admin/commission-plans', { method: 'POST', body: { name: planName } });
            setPlanName('');
            load();
        } catch (err) {
            planErrors.capture(err);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!rules) return <Spinner />;
    const excludable = ['product', 'category'].includes(form.scope);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Commission rules</h1>
                {canManage && <Button onClick={() => open('new')}>New rule</Button>}
            </div>
            <Card title="How a rate is chosen">
                <p className="mb-2 text-sm text-muted">For each product in a paid order, the first matching rule wins. Exclusions always win. Rules in force when the order was placed are used.</p>
                <ol className="list-decimal space-y-0.5 pl-5 text-sm">
                    {PRECEDENCE.map((p) => (
                        <li key={p}>{p}</li>
                    ))}
                </ol>
            </Card>
            <Card title="Rules">
                {rules.length === 0 ? (
                    <p className="text-sm text-muted">No rules yet. Without a rule, paid orders earn no commission. Start with a global default.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rules.map((r) => (
                            <li key={r.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="font-medium text-ink">
                                        {r.name} <span className="text-muted">v{r.version}</span>
                                    </p>
                                    <p className="text-muted">
                                        {r.scope_label}
                                        {r.target ? `: ${r.target.name}` : ''} · {rateLabel(r.kind, r.rate_bp, r.fixed_minor, r.currency ?? 'NPR')} · from {formatDateTime(r.starts_at)}
                                        {r.ends_at ? ` to ${formatDateTime(r.ends_at)}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge status={r.is_active ? 'approved' : 'none'} label={r.is_active ? 'Active' : 'Inactive'} />
                                    {canManage && (
                                        <Button variant="secondary" onClick={() => open(r)}>
                                            Edit
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
            <Card title="Commission plans">
                <p className="mb-3 text-sm text-muted">A plan groups affiliates who share a default rate. Add a plan rule for the rate, then put affiliates on the plan from their profile.</p>
                {plans.length > 0 && (
                    <ul className="mb-4 divide-y divide-line text-sm">
                        {plans.map((p) => (
                            <li key={p.id} className="flex justify-between py-2">
                                <span>{p.name}</span>
                                <span className="text-muted">{p.affiliates_count} affiliates</span>
                            </li>
                        ))}
                    </ul>
                )}
                {canManage && (
                    <form onSubmit={addPlan} className="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <Field label="New plan name" value={planName} onChange={(e) => setPlanName(e.target.value)} error={planErrors.fieldErrors.name} />
                        </div>
                        <Button type="submit" variant="secondary">
                            Add plan
                        </Button>
                    </form>
                )}
            </Card>
            <Modal title={editing === 'new' ? 'New commission rule' : 'Edit rule'} open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={submit} className="max-h-[75vh] space-y-3 overflow-y-auto">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Name" value={form.name} onChange={(e) => set({ name: e.target.value })} error={fieldErrors.name} />
                    {editing === 'new' ? (
                        <>
                            <Select label="Applies to" value={form.scope} onChange={(e) => (setTargets([]), set({ scope: e.target.value, target_id: '', kind: form.kind === 'exclude' ? 'percent' : form.kind }))} error={fieldErrors.scope}>
                                {SCOPES.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </Select>
                            {form.scope !== 'global' && (
                                <Select label="Which one" value={form.target_id} onChange={(e) => set({ target_id: e.target.value })} error={fieldErrors.target_id}>
                                    <option value="">Choose…</option>
                                    {targets.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </>
                    ) : (
                        editing && (
                            <p className="text-sm text-muted">
                                {editing.scope_label}
                                {editing.target ? `: ${editing.target.name}` : ''}. To change what a rule applies to, end it and create a new one.
                            </p>
                        )
                    )}
                    <Select label="Commission" value={form.kind} onChange={(e) => set({ kind: e.target.value })} error={fieldErrors.kind}>
                        <option value="percent">Percentage of what the customer paid</option>
                        <option value="fixed">Fixed amount per unit</option>
                        {(excludable || form.kind === 'exclude') && <option value="exclude">No commission (exclude)</option>}
                    </Select>
                    {form.kind === 'percent' && (
                        <Field label="Rate (%)" inputMode="decimal" value={form.rate} onChange={(e) => set({ rate: e.target.value })} error={fieldErrors.rate_bp} hint="e.g. 10 for 10%" />
                    )}
                    {form.kind === 'fixed' && (
                        <Field label="Amount per unit (NPR)" inputMode="decimal" value={form.fixed} onChange={(e) => set({ fixed: e.target.value })} error={fieldErrors.fixed_minor} hint="Never more than the line's price" />
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Starts" type="datetime-local" value={form.starts_at} onChange={(e) => set({ starts_at: e.target.value })} error={fieldErrors.starts_at} />
                        <Field label="Ends (optional)" type="datetime-local" value={form.ends_at} onChange={(e) => set({ ends_at: e.target.value })} error={fieldErrors.ends_at} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_active} onChange={(e) => set({ is_active: e.target.checked })} className="size-4" />
                        Active
                    </label>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>
                            Close
                        </Button>
                        <Button type="submit" loading={saving}>
                            Save rule
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
