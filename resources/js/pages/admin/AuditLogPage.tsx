import { useEffect, useState } from 'react';
import { Alert, Button, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';

interface AuditEntry {
    id: number;
    action: string;
    actor: { id: string; name: string } | null;
    subject_type: string | null;
    subject_id: string | null;
    created_at: string;
}

interface Page {
    data: AuditEntry[];
    meta: { current_page: number; last_page: number; total: number };
}

const dateFormat = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kathmandu' });

export default function AuditLogPage() {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        api<Page>(`/admin/audit-logs?page=${page}`)
            .then((res) => !cancelled && (setResult(res), setError(null)))
            .catch((e) => !cancelled && setError(e instanceof ApiError ? e.message : 'Could not load the audit log.'));
        return () => {
            cancelled = true;
        };
    }, [page]);

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Audit log</h1>
            {error && <Alert tone="error">{error}</Alert>}
            {!result && !error && <Spinner />}
            {result && (
                <Card>
                    {result.data.length === 0 ? (
                        <p className="text-sm text-muted">No audit entries yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-line text-muted">
                                    <tr>
                                        <th scope="col" className="py-2 pr-4 font-medium">When (Nepal time)</th>
                                        <th scope="col" className="py-2 pr-4 font-medium">Action</th>
                                        <th scope="col" className="py-2 pr-4 font-medium">By</th>
                                        <th scope="col" className="py-2 font-medium">Subject</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.data.map((row) => (
                                        <tr key={row.id} className="border-b border-line last:border-0">
                                            <td className="whitespace-nowrap py-2 pr-4">{dateFormat.format(new Date(row.created_at))}</td>
                                            <td className="py-2 pr-4 font-mono text-xs">{row.action}</td>
                                            <td className="py-2 pr-4">{row.actor?.name ?? 'System'}</td>
                                            <td className="py-2 text-muted">{row.subject_type ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div className="mt-4 flex items-center justify-between text-sm text-muted">
                        <span>
                            Page {result.meta.current_page} of {result.meta.last_page} · {result.meta.total} entries
                        </span>
                        <div className="flex gap-2">
                            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                Previous
                            </Button>
                            <Button variant="secondary" disabled={page >= result.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                                Next
                            </Button>
                        </div>
                    </div>
                </Card>
            )}
        </div>
    );
}
