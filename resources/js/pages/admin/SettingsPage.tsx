import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';

type Value = number | boolean | string;

interface Setting {
    key: string;
    group: string;
    label: string;
    help: string;
    type: 'int' | 'money' | 'bool' | 'choice';
    min: number | null;
    max: number | null;
    choices: { value: string; label: string }[] | null;
    value: Value;
    default: Value;
    overridden: boolean;
    updated_at: string | null;
    updated_by: string | null;
}

/** What the input shows: money in rupees, everything else as stored. */
const toInput = (s: Setting, v: Value): string | boolean => (s.type === 'bool' ? Boolean(v) : s.type === 'money' ? String(Number(v) / 100) : String(v));
const fromInput = (s: Setting, v: string | boolean): Value =>
    s.type === 'bool' ? Boolean(v) : s.type === 'money' ? Math.round(Number(v) * 100) : s.type === 'int' ? Number(v) : String(v);

function describe(s: Setting, v: Value): string {
    if (s.type === 'bool') return v ? 'On' : 'Off';
    if (s.type === 'money') return formatMoney(Number(v));
    if (s.type === 'choice') return s.choices?.find((c) => c.value === v)?.label ?? String(v);
    return String(v);
}

export default function SettingsPage() {
    const [settings, setSettings] = useState<Setting[] | null>(null);
    const [draft, setDraft] = useState<Record<string, string | boolean>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const take = useCallback((data: Setting[]) => {
        setSettings(data);
        setDraft(Object.fromEntries(data.map((s) => [s.key, toInput(s, s.value)])));
    }, []);

    useEffect(() => {
        api<{ data: Setting[] }>('/admin/settings')
            .then((r) => take(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load settings.'));
    }, [take]);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!settings) return <Spinner />;

    const changed = settings.filter((s) => fromInput(s, draft[s.key] ?? '') !== s.value);

    const save = async () => {
        setSaving(true);
        setErrors({});
        setNotice(null);
        try {
            const r = await api<{ data: Setting[]; changed: string[] }>('/admin/settings', {
                method: 'PATCH',
                body: { settings: changed.map((s) => ({ key: s.key, value: fromInput(s, draft[s.key] ?? '') })) },
            });
            take(r.data);
            setNotice(r.changed.length ? `Saved ${r.changed.length} change(s). They apply straight away.` : 'Nothing changed.');
        } catch (err) {
            if (err instanceof ApiError) {
                const byKey: Record<string, string> = {};
                Object.entries(err.errors).forEach(([field, messages]) => {
                    const index = Number(/^settings\.(\d+)\./.exec(field)?.[1]);
                    const setting = changed[index];
                    if (setting) byKey[setting.key] = (Array.isArray(messages) ? messages[0] : String(messages)) ?? '';
                });
                setErrors(byKey);
                setNotice(Object.keys(byKey).length ? null : err.message);
            }
        } finally {
            setSaving(false);
        }
    };

    const reset = async (s: Setting) => {
        if (!window.confirm(`Return “${s.label}” to its default (${describe(s, s.default)})?`)) return;
        try {
            take((await api<{ data: Setting[] }>(`/admin/settings/${s.key}`, { method: 'DELETE' })).data);
            setNotice('Returned to the default.');
        } catch (err) {
            setNotice(err instanceof ApiError ? err.message : 'Could not reset it.');
        }
    };

    const groups = [...new Set(settings.map((s) => s.group))];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Settings</h1>
                <Button onClick={save} loading={saving} disabled={changed.length === 0}>
                    {changed.length ? `Save ${changed.length} change(s)` : 'Save'}
                </Button>
            </div>
            <p className="text-sm text-muted">
                Programme rules for everyone. Changes apply straight away and are recorded in the audit log. Payment keys, email and other technical settings
                stay in the server’s <code>.env</code> file.
            </p>
            {notice && <Alert tone="success">{notice}</Alert>}
            {groups.map((group) => (
                <Card key={group} title={group}>
                    <ul className="divide-y divide-line">
                        {settings
                            .filter((s) => s.group === group)
                            .map((s) => {
                                const id = `setting-${s.key}`;
                                const value = draft[s.key];
                                return (
                                    <li key={s.key} className="grid gap-2 py-3 sm:grid-cols-[1fr_16rem] sm:items-start">
                                        <div className="space-y-1">
                                            <label htmlFor={id} className="block text-sm font-medium text-ink">
                                                {s.label}
                                            </label>
                                            {s.help && <p className="text-sm text-muted">{s.help}</p>}
                                            <p className="text-xs text-muted">
                                                Default {describe(s, s.default)}
                                                {s.overridden && s.updated_at ? ` · changed ${formatDateTime(s.updated_at)}${s.updated_by ? ` by ${s.updated_by}` : ''}` : ''}
                                                {s.overridden && (
                                                    <>
                                                        {' · '}
                                                        <button type="button" className="text-brand-600 hover:underline" onClick={() => reset(s)}>
                                                            Use default
                                                        </button>
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                        <div className="space-y-1">
                                            {s.type === 'bool' ? (
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        id={id}
                                                        type="checkbox"
                                                        className="size-4"
                                                        checked={Boolean(value)}
                                                        onChange={(e) => setDraft((d) => ({ ...d, [s.key]: e.target.checked }))}
                                                    />
                                                    {value ? 'On' : 'Off'}
                                                </label>
                                            ) : s.type === 'choice' ? (
                                                <select
                                                    id={id}
                                                    value={String(value)}
                                                    onChange={(e) => setDraft((d) => ({ ...d, [s.key]: e.target.value }))}
                                                    className="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm"
                                                >
                                                    {s.choices?.map((c) => (
                                                        <option key={c.value} value={c.value}>
                                                            {c.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    {s.type === 'money' && <span className="text-sm text-muted">NPR</span>}
                                                    <input
                                                        id={id}
                                                        type="number"
                                                        inputMode={s.type === 'money' ? 'decimal' : 'numeric'}
                                                        step={s.type === 'money' ? '0.01' : '1'}
                                                        min={s.min === null ? undefined : s.type === 'money' ? s.min / 100 : s.min}
                                                        max={s.max === null ? undefined : s.type === 'money' ? s.max / 100 : s.max}
                                                        value={String(value)}
                                                        onChange={(e) => setDraft((d) => ({ ...d, [s.key]: e.target.value }))}
                                                        aria-invalid={!!errors[s.key]}
                                                        className="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm"
                                                    />
                                                </div>
                                            )}
                                            {errors[s.key] && <p className="text-sm text-danger">{errors[s.key]}</p>}
                                        </div>
                                    </li>
                                );
                            })}
                    </ul>
                </Card>
            ))}
        </div>
    );
}
