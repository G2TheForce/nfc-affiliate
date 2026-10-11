import type React from 'react';
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { Alert, Button, Card, Modal, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { channelLabel, formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';
import { AffiliateEarningsCard } from './AffiliateEarningsCard';

interface Detail {
    id: string;
    status: string;
    status_label: string;
    allowed_transitions: string[];
    display_name: string;
    user: { id: string; name: string; email: string; phone: string | null; email_verified: boolean; registered_at: string };
    referral_code: string | null;
    commission_plan: { id: string; name: string } | null;
    referral_slug: string | null;
    country_code: string;
    city: string | null;
    promotion_channels: string[];
    website_urls: string[];
    audience_description: string | null;
    applied_at: string;
    approved_at: string | null;
    approved_by: string | null;
    history: { from: string | null; to: string; actor: string | null; reason: string | null; internal_note: string | null; at: string }[];
    notes: { author: string | null; body: string; at: string }[];
    terms_accepted: { version: string; accepted_at: string }[];
    possible_duplicates: { id: string; display_name: string; status: string; matched_on: string[] }[];
    can: { review: boolean; suspend: boolean };
}

type Action = 'approve' | 'reject' | 'suspend' | 'reinstate' | 'deactivate' | 'reactivate';

const ACTIONS: Record<Action, { label: string; from: string[]; needs: 'review' | 'suspend'; reasonRequired: boolean; danger?: boolean; help: string }> = {
    approve: { label: 'Approve', from: ['pending'], needs: 'review', reasonRequired: false, help: 'Assigns a referral code and gives the member affiliate access.' },
    reject: { label: 'Reject', from: ['pending'], needs: 'review', reasonRequired: true, danger: true, help: 'The reason is emailed to the applicant.' },
    suspend: { label: 'Suspend', from: ['approved'], needs: 'suspend', reasonRequired: true, danger: true, help: 'Referrals stop earning while suspended. The reason is shown to the member.' },
    reinstate: { label: 'Reinstate', from: ['suspended'], needs: 'suspend', reasonRequired: true, help: 'Restores the membership. The reason is shown to the member.' },
    deactivate: { label: 'Deactivate', from: ['approved', 'suspended'], needs: 'suspend', reasonRequired: true, danger: true, help: 'Closes the membership and removes affiliate access.' },
    reactivate: { label: 'Reactivate', from: ['deactivated'], needs: 'suspend', reasonRequired: true, help: 'Re-opens a closed membership with the same referral code.' },
};

export default function AffiliateDetailPage() {
    const { id } = useParams();
    const [detail, setDetail] = useState<Detail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [action, setAction] = useState<Action | null>(null);

    useEffect(() => {
        let active = true;
        api<{ data: Detail }>(`/admin/affiliates/${id}`)
            .then((r) => active && setDetail(r.data))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load this affiliate.'));
        return () => {
            active = false;
        };
    }, [id]);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!detail) return <Spinner />;

    const available = (Object.keys(ACTIONS) as Action[]).filter((a) => ACTIONS[a].from.includes(detail.status) && detail.can[ACTIONS[a].needs]);

    return (
        <div className="space-y-6">
            <Link to="/admin/affiliates" className="text-sm text-brand-600 hover:underline">
                ← All affiliates
            </Link>
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold text-ink">{detail.display_name}</h1>
                <StatusBadge status={detail.status} label={detail.status_label} />
            </div>

            {available.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {available.map((a) => (
                        <Button key={a} variant={ACTIONS[a].danger ? 'secondary' : 'primary'} onClick={() => setAction(a)}>
                            {ACTIONS[a].label}
                        </Button>
                    ))}
                </div>
            )}

            {detail.possible_duplicates.length > 0 && (
                <Alert tone="error">
                    Possible duplicate accounts (check before approving):{' '}
                    {detail.possible_duplicates.map((d, i) => (
                        <span key={d.id}>
                            {i > 0 && ', '}
                            <Link to={`/admin/affiliates/${d.id}`} className="underline">
                                {d.display_name}
                            </Link>{' '}
                            ({d.status}, same {d.matched_on.join(' and ')})
                        </span>
                    ))}
                </Alert>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Applicant">
                    <dl className="grid grid-cols-1 gap-3 text-sm">
                        <Item label="Name">{detail.user.name}</Item>
                        <Item label="Email">
                            {detail.user.email} {detail.user.email_verified ? '(verified)' : '(not verified)'}
                        </Item>
                        <Item label="Phone">{detail.user.phone ?? '—'}</Item>
                        <Item label="Location">{[detail.city, detail.country_code].filter(Boolean).join(', ')}</Item>
                        <Item label="Registered">{formatDateTime(detail.user.registered_at)}</Item>
                    </dl>
                </Card>
                <Card title="Programme">
                    <dl className="grid grid-cols-1 gap-3 text-sm">
                        <Item label="Applied">{formatDateTime(detail.applied_at)}</Item>
                        <Item label="Approved">{detail.approved_at ? `${formatDateTime(detail.approved_at)} by ${detail.approved_by ?? '—'}` : '—'}</Item>
                        <Item label="Referral code">
                            <span className="font-mono">{detail.referral_code ?? '—'}</span>
                        </Item>
                        <Item label="Terms accepted">{detail.terms_accepted.map((t) => `${t.version} (${formatDateTime(t.accepted_at)})`).join(', ') || '—'}</Item>
                    </dl>
                </Card>
            </div>

            <AffiliateEarningsCard affiliateId={detail.id} plan={detail.commission_plan} />

            <Card title="Promotion plan">
                <dl className="grid grid-cols-1 gap-3 text-sm">
                    <Item label="Channels">{detail.promotion_channels.map(channelLabel).join(', ')}</Item>
                    <Item label="Links">
                        {detail.website_urls.length === 0
                            ? '—'
                            : detail.website_urls.map((u) => (
                                  <div key={u}>
                                      <a href={u} target="_blank" rel="noopener noreferrer nofollow" className="break-all text-brand-600 hover:underline">
                                          {u}
                                      </a>
                                  </div>
                              ))}
                    </Item>
                    <Item label="Audience">{detail.audience_description ?? '—'}</Item>
                </dl>
            </Card>

            <Notes detail={detail} onChange={setDetail} />

            <Card title="Status history">
                <ol className="space-y-3 text-sm">
                    {detail.history.map((h, i) => (
                        <li key={i} className="border-b border-line pb-3 last:border-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={h.to} />
                                <span className="text-muted">
                                    {formatDateTime(h.at)} · {h.actor ?? 'System'}
                                </span>
                            </div>
                            {h.reason && <p className="mt-1">Reason shown to member: {h.reason}</p>}
                            {h.internal_note && <p className="mt-1 text-muted">Internal: {h.internal_note}</p>}
                        </li>
                    ))}
                </ol>
            </Card>

            {action && <ActionDialog action={action} detail={detail} onClose={() => setAction(null)} onDone={(d) => (setDetail(d), setAction(null))} />}
        </div>
    );
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted">{label}</dt>
            <dd className="text-ink">{children}</dd>
        </div>
    );
}

function ActionDialog({ action, detail, onClose, onDone }: { action: Action; detail: Detail; onClose: () => void; onDone: (d: Detail) => void }) {
    const config = ACTIONS[action];
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setBusy(true);
        try {
            const res = await api<{ data: Detail }>(`/admin/affiliates/${detail.id}/${action}`, {
                method: 'POST',
                body: { reason: reason || null, internal_note: note || null },
            });
            onDone(res.data);
        } catch (err) {
            capture(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal title={`${config.label} ${detail.display_name}?`} open onClose={onClose}>
            <form onSubmit={submit} className="space-y-4" noValidate>
                <p className="text-sm text-muted">{config.help}</p>
                {(message || fieldErrors.status) && <Alert tone="error">{message ?? fieldErrors.status}</Alert>}
                <TextArea
                    label={config.reasonRequired ? 'Reason (shown to the member)' : 'Message to the member (optional)'}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    error={fieldErrors.reason}
                    required={config.reasonRequired}
                />
                <TextArea label="Internal note (staff only, optional)" value={note} onChange={(e) => setNote(e.target.value)} error={fieldErrors.internal_note} />
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" loading={busy}>
                        {config.label}
                    </Button>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function Notes({ detail, onChange }: { detail: Detail; onChange: (d: Detail) => void }) {
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const { fieldErrors, capture, reset } = useFormErrors();

    const add = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setBusy(true);
        try {
            const res = await api<{ data: Detail }>(`/admin/affiliates/${detail.id}/notes`, { method: 'POST', body: { body } });
            onChange(res.data);
            setBody('');
        } catch (err) {
            capture(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card title="Internal notes">
            <p className="mb-3 text-xs text-muted">Only staff can see these notes.</p>
            {detail.notes.length === 0 ? (
                <p className="text-sm text-muted">No notes yet.</p>
            ) : (
                <ul className="space-y-3 text-sm">
                    {detail.notes.map((n, i) => (
                        <li key={i}>
                            <p className="whitespace-pre-wrap text-ink">{n.body}</p>
                            <p className="text-xs text-muted">
                                {n.author ?? 'Unknown'} · {formatDateTime(n.at)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            {detail.can.review && (
                <form onSubmit={add} className="mt-4 space-y-2" noValidate>
                    <TextArea label="Add a note" value={body} onChange={(e) => setBody(e.target.value)} error={fieldErrors.body} />
                    <Button type="submit" variant="secondary" loading={busy} disabled={!body.trim()}>
                        Add note
                    </Button>
                </form>
            )}
        </Card>
    );
}
