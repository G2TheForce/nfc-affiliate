import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field, Spinner, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Version {
    version: string;
    title: string;
    body: string;
    published_at: string;
    is_current: boolean;
}

export default function TermsPage() {
    const { can } = useAuth();
    const [versions, setVersions] = useState<Version[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [reload, setReload] = useState(0);
    const [form, setForm] = useState({ version: '', title: 'Affiliate programme terms', body: '' });
    const [busy, setBusy] = useState(false);
    const [published, setPublished] = useState<string | null>(null);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    useEffect(() => {
        api<{ data: Version[] }>('/admin/terms')
            .then((r) => setVersions(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load terms.'));
    }, [reload]);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setBusy(true);
        try {
            await api('/admin/terms', { method: 'POST', body: form });
            setPublished(form.version);
            setForm({ ...form, version: '', body: '' });
            setReload((r) => r + 1);
        } catch (err) {
            capture(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Affiliate terms</h1>
            {error && <Alert tone="error">{error}</Alert>}
            {!versions && !error && <Spinner />}
            {versions && versions.length === 0 && <Alert>No terms published yet. Applicants cannot apply until you publish the first version.</Alert>}
            {versions?.map((v) => (
                <Card key={v.version} title={`${v.title} (${v.version})${v.is_current ? ' · current' : ''}`}>
                    <p className="mb-2 text-xs text-muted">Published {formatDateTime(v.published_at)}</p>
                    <details>
                        <summary className="cursor-pointer text-sm text-brand-600">Show text</summary>
                        <div className="mt-2 whitespace-pre-wrap text-sm text-muted">{v.body}</div>
                    </details>
                </Card>
            ))}
            {can('settings.manage') && (
                <Card title="Publish a new version">
                    <p className="mb-4 text-sm text-muted">
                        Published terms cannot be edited. Approved affiliates are asked to accept each new version. Have the text reviewed by a qualified
                        lawyer before publishing.
                    </p>
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        {message && <Alert tone="error">{message}</Alert>}
                        {published && <Alert tone="success">Version {published} published.</Alert>}
                        <Field label="Version label" hint="For example 2026-11. Letters, numbers, dots and dashes." value={form.version} onChange={(e) => setForm({ ...form, version: e.target.value })} error={fieldErrors.version} />
                        <Field label="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} error={fieldErrors.title} />
                        <TextArea label="Terms text" rows={12} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} error={fieldErrors.body} />
                        <Button type="submit" loading={busy}>
                            Publish version
                        </Button>
                    </form>
                </Card>
            )}
        </div>
    );
}
