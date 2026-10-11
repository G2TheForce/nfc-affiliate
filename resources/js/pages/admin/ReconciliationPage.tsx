import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Card, Spinner, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';

interface Issue {
    type: string;
    commission?: string;
    adjustment?: string;
    bucket?: string;
    expected_minor?: number;
    ledger_minor?: number;
    count?: number;
}

interface Run {
    id: string;
    result: 'clean' | 'drift';
    commissions_checked: number;
    affiliates_checked: number;
    entries_checked: number;
    issues: Issue[];
    triggered_by: string;
    started_at: string;
    finished_at: string;
}

const ISSUE_LABELS: Record<string, string> = {
    accrual_mismatch: 'Earned amount differs from the commission',
    reversal_mismatch: 'Reversed amount differs from the commission',
    bucket_mismatch: 'Balance bucket differs from the commission status',
    adjustment_mismatch: 'Adjustment and ledger disagree',
    unlinked_entries: 'Ledger entries not linked to any record',
};

export default function ReconciliationPage() {
    const [runs, setRuns] = useState<Run[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [running, setRunning] = useState(false);

    const load = useCallback(() => {
        api<{ data: Run[] }>('/admin/reconciliation')
            .then((r) => setRuns(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load reconciliation results.'));
    }, []);

    useEffect(load, [load]);

    const runNow = async () => {
        setRunning(true);
        try {
            await api('/admin/reconciliation', { method: 'POST' });
            load();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Could not run the check.');
        } finally {
            setRunning(false);
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!runs) return <Spinner />;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Reconciliation</h1>
                <Button onClick={runNow} loading={running}>
                    Run check now
                </Button>
            </div>
            <p className="text-sm text-muted">
                Every night the ledger is re-derived from commissions and adjustments and compared. A drift result means something changed the ledger outside the app. The
                check never corrects anything itself.
            </p>
            {runs.length === 0 ? (
                <Card>
                    <p className="text-sm text-muted">No checks have run yet.</p>
                </Card>
            ) : (
                runs.map((run) => (
                    <Card key={run.id}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm">
                                {formatDateTime(run.finished_at)} · {run.triggered_by} · {run.commissions_checked} commissions, {run.entries_checked} entries, {run.affiliates_checked} affiliates
                            </p>
                            <StatusBadge status={run.result} label={run.result === 'clean' ? 'Clean' : `Drift: ${run.issues.length} issue${run.issues.length === 1 ? '' : 's'}`} />
                        </div>
                        {run.issues.length > 0 && (
                            <ul className="mt-3 divide-y divide-line text-sm">
                                {run.issues.map((i, n) => (
                                    <li key={n} className="py-2">
                                        <p className="font-medium text-ink">{ISSUE_LABELS[i.type] ?? i.type}</p>
                                        <p className="text-muted">
                                            {[
                                                i.commission && `commission ${i.commission}`,
                                                i.adjustment && `adjustment ${i.adjustment}`,
                                                i.bucket && `bucket ${i.bucket}`,
                                                i.expected_minor !== undefined && `expected ${formatMoney(i.expected_minor)}`,
                                                i.ledger_minor !== undefined && `ledger ${formatMoney(i.ledger_minor)}`,
                                                i.count !== undefined && `${i.count} entries`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                ))
            )}
        </div>
    );
}
