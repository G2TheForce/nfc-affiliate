import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Alert, Button, Card, Spinner, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { channelLabel, formatDate } from '@/lib/format';

interface Row {
    id: string;
    display_name: string;
    name: string;
    email: string;
    status: string;
    status_label: string;
    referral_code: string | null;
    promotion_channels: string[];
    applied_at: string;
}

interface Page {
    data: Row[];
    meta: { current_page: number; last_page: number; total: number };
}

const TABS = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'deactivated', label: 'Deactivated' },
];

export default function AffiliatesListPage() {
    const [params, setParams] = useSearchParams();
    const status = params.get('status') ?? '';
    const search = params.get('search') ?? '';
    const page = Number(params.get('page') ?? '1');
    const [query, setQuery] = useState(search);
    const [result, setResult] = useState<Page | null>(null);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        const qs = new URLSearchParams({ page: String(page) });
        if (status) qs.set('status', status);
        if (search) qs.set('search', search);
        api<Page>(`/admin/affiliates?${qs}`)
            .then((r) => active && (setResult(r), setError(null)))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load affiliates.'));
        api<{ data: Record<string, number> }>('/admin/affiliates/counts')
            .then((r) => active && setCounts(r.data))
            .catch(() => undefined);
        return () => {
            active = false;
        };
    }, [status, search, page]);

    const update = (next: Record<string, string>) => {
        const merged = new URLSearchParams(params);
        Object.entries(next).forEach(([k, v]) => (v ? merged.set(k, v) : merged.delete(k)));
        setParams(merged);
    };

    const onSearch = (e: FormEvent) => {
        e.preventDefault();
        update({ search: query, page: '' });
    };

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Affiliates</h1>
            <div className="flex flex-wrap gap-2" role="tablist" aria-label="Filter by status">
                {TABS.map((t) => (
                    <button
                        key={t.value}
                        role="tab"
                        aria-selected={status === t.value}
                        onClick={() => update({ status: t.value, page: '' })}
                        className={`min-h-9 rounded-full border px-3 text-sm font-medium ${
                            status === t.value ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-canvas'
                        }`}
                    >
                        {t.label}
                        {t.value && counts[t.value] !== undefined && <span className="ml-1 opacity-80">({counts[t.value]})</span>}
                    </button>
                ))}
            </div>
            <form onSubmit={onSearch} className="flex gap-2">
                <label htmlFor="aff-search" className="sr-only">
                    Search affiliates
                </label>
                <input
                    id="aff-search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Name, email or referral code"
                    className="min-h-11 w-full max-w-sm rounded-lg border border-line bg-surface px-3 text-sm"
                />
                <Button type="submit" variant="secondary">
                    Search
                </Button>
            </form>
            {error && <Alert tone="error">{error}</Alert>}
            {!result && !error && <Spinner />}
            {result && (
                <Card>
                    {result.data.length === 0 ? (
                        <p className="text-sm text-muted">No affiliates match these filters.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-line text-muted">
                                    <tr>
                                        <th scope="col" className="py-2 pr-4 font-medium">Affiliate</th>
                                        <th scope="col" className="py-2 pr-4 font-medium">Status</th>
                                        <th scope="col" className="hidden py-2 pr-4 font-medium md:table-cell">Channels</th>
                                        <th scope="col" className="py-2 pr-4 font-medium">Applied</th>
                                        <th scope="col" className="hidden py-2 font-medium sm:table-cell">Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.data.map((r) => (
                                        <tr key={r.id} className="border-b border-line last:border-0">
                                            <td className="py-3 pr-4">
                                                <Link to={`/admin/affiliates/${r.id}`} className="font-medium text-brand-700 hover:underline">
                                                    {r.display_name}
                                                </Link>
                                                <div className="text-xs text-muted">{r.email}</div>
                                            </td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge status={r.status} label={r.status_label} />
                                            </td>
                                            <td className="hidden py-3 pr-4 text-muted md:table-cell">{r.promotion_channels.map(channelLabel).join(', ')}</td>
                                            <td className="whitespace-nowrap py-3 pr-4">{formatDate(r.applied_at)}</td>
                                            <td className="hidden py-3 font-mono text-xs sm:table-cell">{r.referral_code ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="mt-4 flex items-center justify-between text-sm text-muted">
                        <span>{result.meta.total} affiliates</span>
                        <div className="flex gap-2">
                            <Button variant="secondary" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
                                Previous
                            </Button>
                            <Button variant="secondary" disabled={page >= result.meta.last_page} onClick={() => update({ page: String(page + 1) })}>
                                Next
                            </Button>
                        </div>
                    </div>
                </Card>
            )}
        </div>
    );
}
