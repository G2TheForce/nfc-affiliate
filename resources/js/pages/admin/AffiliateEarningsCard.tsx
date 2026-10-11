import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Select, TextArea } from '@/components/ui';
import { movement, type Buckets, type LedgerEntry } from '@/commissions/types';
import { api } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface Plan {
    id: string;
    name: string;
}

/** Commission plan and ledger balances for one affiliate, on the staff profile page. */
export function AffiliateEarningsCard({ affiliateId, plan }: { affiliateId: string; plan: Plan | null }) {
    const { can } = useAuth();
    const canView = can('commissions.view');
    const canManage = can('commission_rules.manage');
    const [current, setCurrent] = useState<Plan | null>(plan);
    const [plans, setPlans] = useState<Plan[]>([]);
    const [choice, setChoice] = useState(plan?.id ?? '');
    const [reason, setReason] = useState('');
    const [ledger, setLedger] = useState<{ data: LedgerEntry[]; balances: Buckets; currency: string } | null>(null);
    const [saved, setSaved] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    useEffect(() => {
        if (canView) api<{ data: LedgerEntry[]; balances: Buckets; currency: string }>(`/admin/affiliates/${affiliateId}/ledger`).then(setLedger).catch(() => undefined);
        if (canManage || canView) api<{ data: Plan[] }>('/admin/commission-plans').then((r) => setPlans(r.data)).catch(() => undefined);
    }, [affiliateId, canView, canManage]);

    if (!canView && !canManage) return null;

    const savePlan = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaved(false);
        try {
            const r = await api<{ data: { plan: Plan | null } }>(`/admin/affiliates/${affiliateId}/plan`, { method: 'POST', body: { plan_id: choice || null, reason } });
            setCurrent(r.data.plan);
            setReason('');
            setSaved(true);
        } catch (err) {
            capture(err);
        }
    };

    const b = ledger?.balances;
    const c = ledger?.currency ?? 'NPR';

    return (
        <Card title="Commission">
            <div className="space-y-4">
                <p className="text-sm">
                    Plan: <strong>{current?.name ?? 'Global default'}</strong>
                </p>
                {b && (
                    <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        {(
                            [
                                ['Pending', b.pending + b.approved],
                                ['Available', b.available],
                                ['Paid', b.paid],
                                ['Reversed', b.reversed],
                            ] as const
                        ).map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-muted">{label}</dt>
                                <dd className="font-semibold tabular-nums">{formatMoney(value, c)}</dd>
                            </div>
                        ))}
                    </dl>
                )}
                {ledger && ledger.data.length > 0 && (
                    <details className="text-sm">
                        <summary className="cursor-pointer text-brand-600">Ledger ({ledger.data.length} latest entries)</summary>
                        <ul className="mt-2 divide-y divide-line">
                            {ledger.data.map((e, i) => (
                                <li key={i} className="flex flex-wrap justify-between gap-2 py-1.5">
                                    <span>
                                        {formatDateTime(e.created_at)} · {e.label} <span className="text-muted">({movement(e)})</span>
                                    </span>
                                    <span className="tabular-nums">{formatMoney(e.amount_minor, e.currency)}</span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
                {canManage && (
                    <form onSubmit={savePlan} className="space-y-3 border-t border-line pt-4">
                        {message && <Alert tone="error">{message}</Alert>}
                        {saved && <Alert tone="success">Plan saved. It applies to commission created from now on.</Alert>}
                        <Select label="Commission plan" value={choice} onChange={(e) => setChoice(e.target.value)} error={fieldErrors.plan_id}>
                            <option value="">Global default</option>
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </Select>
                        <TextArea label="Reason for the change" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} error={fieldErrors.reason} />
                        <Button type="submit" variant="secondary">
                            Save plan
                        </Button>
                    </form>
                )}
            </div>
        </Card>
    );
}
