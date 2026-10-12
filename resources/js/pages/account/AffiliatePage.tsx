import { useEffect, useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { AffiliateDetailsFields, emptyDetails, toPayload, type DetailsForm } from '@/affiliate/AffiliateDetailsFields';
import type { AffiliateProfile, Terms } from '@/affiliate/types';
import { Alert, Button, Card, Field, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

function fromProfile(p: AffiliateProfile): DetailsForm {
    return {
        display_name: p.display_name,
        country_code: p.country_code,
        city: p.city ?? '',
        address_line: p.address_line ?? '',
        promotion_channels: p.promotion_channels,
        website_urls: p.website_urls.join('\n'),
        audience_description: p.audience_description ?? '',
    };
}

export default function AffiliatePage() {
    const { refresh } = useAuth();
    const [profile, setProfile] = useState<AffiliateProfile | null | undefined>(undefined);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        api<{ data: AffiliateProfile | null }>('/affiliate/profile')
            .then((res) => active && setProfile(res.data))
            .catch((e) => active && setError(e instanceof ApiError ? e.message : 'Could not load your affiliate details.'));
        return () => {
            active = false;
        };
    }, []);

    const onChanged = async (p: AffiliateProfile) => {
        setProfile(p);
        await refresh(); // roles change on approval or closure
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (profile === undefined) return <Spinner />;

    const canReapply = profile?.status === 'rejected' && profile.can_reapply_at && new Date(profile.can_reapply_at) <= new Date();

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-semibold text-ink">Affiliate programme</h1>
                {profile && <StatusBadge status={profile.status} label={profile.status_label} />}
            </div>

            {!profile && <ApplyForm onApplied={onChanged} />}
            {profile && <StatusCard profile={profile} />}
            {profile?.needs_terms_acceptance && ['approved', 'suspended'].includes(profile.status) && (
                <AcceptTerms onAccepted={onChanged} />
            )}
            {profile?.status === 'approved' && <ReferralCard profile={profile} />}
            {profile && ['pending', 'approved', 'suspended'].includes(profile.status) && <EditProfile profile={profile} onSaved={onChanged} />}
            {canReapply && profile && <ApplyForm initial={fromProfile(profile)} onApplied={onChanged} reapply />}
            {profile?.status === 'approved' && <CloseMembership onClosed={onChanged} />}
            {profile && <History profile={profile} />}
        </div>
    );
}

function StatusCard({ profile }: { profile: AffiliateProfile }) {
    const latestReason = profile.history.find((h) => h.reason)?.reason;
    const text: Record<AffiliateProfile['status'], string> = {
        pending: `We received your application on ${formatDate(profile.applied_at)}. We'll email you when it has been reviewed.`,
        approved: 'You are an approved affiliate.',
        rejected: profile.can_reapply_at
            ? `Your application was not approved. You can apply again from ${formatDate(profile.can_reapply_at)}.`
            : 'Your application was not approved.',
        suspended: 'Your membership is suspended. Referrals do not earn commissions while it is suspended. Contact us if you have questions.',
        deactivated: 'Your affiliate membership is closed.',
    };
    return (
        <Card>
            <p className="text-sm text-ink">{text[profile.status]}</p>
            {latestReason && profile.status !== 'approved' && <p className="mt-2 text-sm text-muted">Reason: {latestReason}</p>}
        </Card>
    );
}

function ReferralCard({ profile }: { profile: AffiliateProfile }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        await navigator.clipboard.writeText(profile.referral_code ?? '');
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };
    return (
        <Card title="Your referral code">
            <div className="flex flex-wrap items-center gap-3">
                <code className="rounded-lg bg-brand-50 px-4 py-2 font-mono text-xl font-bold tracking-widest text-brand-700">{profile.referral_code}</code>
                <Button variant="secondary" onClick={copy}>
                    {copied ? 'Copied' : 'Copy code'}
                </Button>
            </div>
            <p className="mt-3 text-sm text-muted">Customers can type this code at checkout, or follow one of your Referral links. Your sales and earnings are on the Affiliate dashboard.</p>
        </Card>
    );
}

function ApplyForm({ onApplied, initial, reapply = false }: { onApplied: (p: AffiliateProfile) => void; initial?: DetailsForm; reapply?: boolean }) {
    const [terms, setTerms] = useState<Terms | null | undefined>(undefined);
    const [form, setForm] = useState<DetailsForm>(initial ?? emptyDetails);
    const [accepted, setAccepted] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    useEffect(() => {
        api<{ data: Terms | null }>('/affiliate/terms/current')
            .then((res) => setTerms(res.data))
            .catch(() => setTerms(null));
    }, []);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!terms) return;
        reset();
        setSubmitting(true);
        try {
            const res = await api<{ data: AffiliateProfile }>('/affiliate/applications', {
                method: 'POST',
                body: { ...toPayload(form), terms_version: terms.version, accept_terms: accepted },
            });
            onApplied(res.data);
        } catch (err) {
            capture(err);
        } finally {
            setSubmitting(false);
        }
    };

    if (terms === undefined) return <Spinner />;
    if (terms === null) {
        return (
            <Card title="Apply to become an affiliate">
                <p className="text-sm text-muted">Applications open soon. Please check back later.</p>
            </Card>
        );
    }

    const generalError = message ?? fieldErrors.status ?? fieldErrors.account ?? fieldErrors.email ?? fieldErrors.terms_version;

    return (
        <Card title={reapply ? 'Apply again' : 'Apply to become an affiliate'}>
            <p className="mb-4 text-sm text-muted">
                Earn a commission on eligible purchases made through your referrals. Commissions depend on real, paid orders; there are no guaranteed
                earnings.
            </p>
            <form onSubmit={submit} className="space-y-5" noValidate>
                {generalError && <Alert tone="error">{generalError}</Alert>}
                <AffiliateDetailsFields form={form} setForm={setForm} errors={fieldErrors} />
                <div>
                    <h3 className="text-sm font-medium text-ink">{terms.title}</h3>
                    <div tabIndex={0} className="mt-2 max-h-48 overflow-y-auto whitespace-pre-wrap rounded-lg border border-line bg-canvas p-3 text-sm text-muted">
                        {terms.body}
                    </div>
                    <label className="mt-3 flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" className="mt-1 h-4 w-4" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                        <span>I have read and accept the affiliate terms (version {terms.version}).</span>
                    </label>
                    {fieldErrors.accept_terms && <p className="mt-1 text-sm text-danger">{fieldErrors.accept_terms}</p>}
                </div>
                <Button type="submit" loading={submitting}>
                    Submit application
                </Button>
            </form>
        </Card>
    );
}

function EditProfile({ profile, onSaved }: { profile: AffiliateProfile; onSaved: (p: AffiliateProfile) => void }) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<DetailsForm>(fromProfile(profile));
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const save = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        setSaved(false);
        try {
            const res = await api<{ data: AffiliateProfile }>('/affiliate/profile', { method: 'PATCH', body: toPayload(form) });
            onSaved(res.data);
            setSaved(true);
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card title="Profile details">
            {!open ? (
                <Button variant="secondary" onClick={() => setOpen(true)}>
                    Edit profile
                </Button>
            ) : (
                <form onSubmit={save} className="space-y-4" noValidate>
                    {message && <Alert tone="error">{message}</Alert>}
                    {saved && <Alert tone="success">Profile saved.</Alert>}
                    <AffiliateDetailsFields form={form} setForm={setForm} errors={fieldErrors} />
                    <div className="flex gap-2">
                        <Button type="submit" loading={saving}>
                            Save changes
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                            Close
                        </Button>
                    </div>
                </form>
            )}
        </Card>
    );
}

function AcceptTerms({ onAccepted }: { onAccepted: (p: AffiliateProfile) => void }) {
    const [terms, setTerms] = useState<Terms | null>(null);
    const [accepted, setAccepted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: Terms | null }>('/affiliate/terms/current').then((r) => setTerms(r.data));
    }, []);

    if (!terms) return null;

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await api<{ data: AffiliateProfile }>('/affiliate/terms/accept', {
                method: 'POST',
                body: { terms_version: terms.version, accept_terms: accepted },
            });
            onAccepted(res.data);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Could not save your acceptance.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card title="Updated affiliate terms">
            <Alert>Our affiliate terms have changed. Please review and accept version {terms.version}.</Alert>
            <div tabIndex={0} className="mt-3 max-h-48 overflow-y-auto whitespace-pre-wrap rounded-lg border border-line bg-canvas p-3 text-sm text-muted">
                {terms.body}
            </div>
            {error && <div className="mt-3"><Alert tone="error">{error}</Alert></div>}
            <label className="mt-3 flex items-start gap-2 text-sm text-ink">
                <input type="checkbox" className="mt-1 h-4 w-4" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                <span>I accept the updated terms.</span>
            </label>
            <Button className="mt-3" onClick={submit} loading={busy} disabled={!accepted}>
                Accept terms
            </Button>
        </Card>
    );
}

function CloseMembership({ onClosed }: { onClosed: (p: AffiliateProfile) => void }) {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setBusy(true);
        try {
            const res = await api<{ data: AffiliateProfile }>('/affiliate/profile/close', { method: 'POST', body: { password, reason: reason || null } });
            onClosed(res.data);
        } catch (err) {
            capture(err);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card title="Close membership">
            {!open ? (
                <Button variant="secondary" onClick={() => setOpen(true)}>
                    Close my affiliate membership
                </Button>
            ) : (
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Alert tone="error">Closing your membership stops new referrals from earning commissions. Staff must reactivate it if you return.</Alert>
                    {message && <Alert tone="error">{message}</Alert>}
                    <TextArea label="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} />
                    <Field
                        label="Confirm with your password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={fieldErrors.password ?? fieldErrors.status}
                    />
                    <div className="flex gap-2">
                        <Button type="submit" loading={busy}>
                            Close membership
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </Card>
    );
}

function History({ profile }: { profile: AffiliateProfile }) {
    return (
        <Card title="History">
            <ol className="space-y-3 text-sm">
                {profile.history.map((h, i) => (
                    <li key={i} className="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
                        <span className="w-44 shrink-0 text-muted">{formatDateTime(h.at)}</span>
                        <span>
                            <StatusBadge status={h.status} label={h.status_label} />
                            {h.reason && <span className="ml-2 text-muted">{h.reason}</span>}
                        </span>
                    </li>
                ))}
            </ol>
        </Card>
    );
}
