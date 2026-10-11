import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { Alert, Button, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { notifyUnreadChanged } from '@/lib/unread';

interface Item {
    id: string;
    title: string;
    body: string;
    link: string | null;
    read: boolean;
    created_at: string;
}

interface Page {
    data: Item[];
    meta: { current_page: number; last_page: number; unread: number };
}

export default function NotificationsPage() {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Page | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [version, setVersion] = useState(0);

    useEffect(() => {
        let active = true;
        api<Page>(`/notifications?page=${page}`)
            .then((r) => active && setResult(r))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load notifications.'));
        return () => {
            active = false;
        };
    }, [page, version]);

    const markRead = async (id: string) => {
        await api(`/notifications/${id}/read`, { method: 'POST' });
        notifyUnreadChanged();
        setVersion((v) => v + 1);
    };

    const markAll = async () => {
        await api('/notifications/read-all', { method: 'POST' });
        notifyUnreadChanged();
        setVersion((v) => v + 1);
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Notifications</h1>
                {result && result.meta.unread > 0 && (
                    <Button variant="secondary" onClick={markAll}>
                        Mark all as read
                    </Button>
                )}
            </div>
            {error && <Alert tone="error">{error}</Alert>}
            {!result && !error && <Spinner />}
            {result && (
                <Card>
                    {result.data.length === 0 ? (
                        <p className="text-sm text-muted">You have no notifications.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {result.data.map((n) => (
                                <li key={n.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0">
                                        <p className={`text-sm ${n.read ? 'text-muted' : 'font-semibold text-ink'}`}>
                                            {!n.read && <span className="mr-2 inline-block h-2 w-2 rounded-full bg-brand-500" aria-label="Unread" />}
                                            {n.link ? <Link to={n.link} className="hover:underline">{n.title}</Link> : n.title}
                                        </p>
                                        <p className="mt-1 text-sm text-muted">{n.body}</p>
                                        <p className="mt-1 text-xs text-muted">{formatDateTime(n.created_at)}</p>
                                    </div>
                                    {!n.read && (
                                        <Button variant="secondary" onClick={() => markRead(n.id)}>
                                            Mark read
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.meta.last_page > 1 && (
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                Previous
                            </Button>
                            <Button variant="secondary" disabled={page >= result.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                                Next
                            </Button>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
