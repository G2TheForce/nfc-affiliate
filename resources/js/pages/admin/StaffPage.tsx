import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Card, Field, Modal, Select, Spinner, StatusBadge, TextArea } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

interface StaffMember {
    id: string;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    status: 'active' | 'disabled';
    last_login_at: string | null;
    created_at: string | null;
    is_you: boolean;
}

interface RoleOption {
    value: string;
    label: string;
}

const ROLE_HINTS: Record<string, string> = {
    super_admin: 'Everything, including staff, settings and the audit log.',
    affiliate_manager: 'Affiliates, commission rules, campaigns, coupons, marketing materials and fraud review.',
    finance_manager: 'Payments, refunds, commission approval, adjustments, payouts and reconciliation.',
    support: 'Looks up affiliates and orders. Cannot change money.',
};

export default function StaffPage() {
    const [staff, setStaff] = useState<StaffMember[] | null>(null);
    const [roles, setRoles] = useState<RoleOption[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', role: 'support' });
    const [statusFor, setStatusFor] = useState<StaffMember | null>(null);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);
    const { fieldErrors, message, capture, reset } = useFormErrors();

    const load = useCallback(
        () =>
            api<{ data: StaffMember[]; roles: RoleOption[] }>('/admin/staff')
                .then((r) => {
                    setStaff(r.data);
                    setRoles(r.roles);
                })
                .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load staff.')),
        [],
    );

    useEffect(() => {
        load();
    }, [load]);

    const add = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSaving(true);
        try {
            await api('/admin/staff', { method: 'POST', body: form });
            setAdding(false);
            setNotice(`Invitation sent to ${form.email.trim().toLowerCase()}. The link works for 60 minutes.`);
            setForm({ name: '', email: '', role: 'support' });
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const changeStatus = async (e: FormEvent) => {
        e.preventDefault();
        if (!statusFor) return;
        reset();
        setSaving(true);
        try {
            await api(`/admin/staff/${statusFor.id}/status`, { method: 'POST', body: { status: statusFor.status === 'active' ? 'disabled' : 'active', reason } });
            setStatusFor(null);
            setReason('');
            await load();
        } catch (err) {
            capture(err);
        } finally {
            setSaving(false);
        }
    };

    const resend = async (member: StaffMember) => {
        setNotice(null);
        try {
            await api(`/admin/staff/${member.id}/invitation`, { method: 'POST' });
            setNotice(`A new invitation was sent to ${member.email}.`);
        } catch (err) {
            setNotice(err instanceof ApiError ? err.message : 'Could not send the invitation.');
        }
    };

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!staff) return <Spinner />;

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold text-ink">Staff</h1>
                <Button
                    onClick={() => {
                        reset();
                        setAdding(true);
                    }}
                >
                    Add staff member
                </Button>
            </div>
            <p className="text-sm text-muted">
                New staff get an email to set their own password. To change someone’s role, disable the account and add them again with the new role, so the audit
                log keeps who did what under which role.
            </p>
            {notice && <Alert tone="success">{notice}</Alert>}
            <Card>
                <ul className="divide-y divide-line text-sm">
                    {staff.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 font-semibold text-ink">
                                    {s.name} {s.is_you && <span className="text-xs font-normal text-muted">(you)</span>}
                                    <StatusBadge status={s.status} label={s.status === 'active' ? 'Active' : 'Disabled'} />
                                </p>
                                <p className="break-all text-muted">
                                    {s.email} · {s.role_label ?? 'No role'} · last signed in {s.last_login_at ? formatDateTime(s.last_login_at) : 'never'}
                                </p>
                            </div>
                            {!s.is_you && (
                                <div className="flex flex-wrap gap-3">
                                    {s.status === 'active' && !s.last_login_at && (
                                        <button type="button" className="text-brand-600 hover:underline" onClick={() => resend(s)}>
                                            Resend invitation
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className={s.status === 'active' ? 'text-danger hover:underline' : 'text-brand-600 hover:underline'}
                                        onClick={() => {
                                            reset();
                                            setReason('');
                                            setStatusFor(s);
                                        }}
                                    >
                                        {s.status === 'active' ? 'Disable' : 'Re-enable'}
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Card>

            <Modal title="Add staff member" open={adding} onClose={() => setAdding(false)}>
                <form onSubmit={add} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Full name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={fieldErrors.name} />
                    <Field label="Email" type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} error={fieldErrors.email} />
                    <Select label="Role" value={form.role} onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))} error={fieldErrors.role}>
                        {roles.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </Select>
                    {ROLE_HINTS[form.role] && <p className="text-sm text-muted">{ROLE_HINTS[form.role]}</p>}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setAdding(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving}>
                            Send invitation
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal title={statusFor?.status === 'active' ? `Disable ${statusFor?.name}` : `Re-enable ${statusFor?.name ?? ''}`} open={statusFor !== null} onClose={() => setStatusFor(null)}>
                <form onSubmit={changeStatus} className="space-y-3">
                    {message && <Alert tone="error">{message}</Alert>}
                    {fieldErrors.status && <Alert tone="error">{fieldErrors.status}</Alert>}
                    {statusFor?.status === 'active' && <p className="text-sm text-muted">They are signed out everywhere at once and cannot sign in until re-enabled.</p>}
                    <TextArea label="Reason (kept in the audit log)" value={reason} onChange={(e) => setReason(e.target.value)} error={fieldErrors.reason} />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setStatusFor(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving}>
                            {statusFor?.status === 'active' ? 'Disable account' : 'Re-enable account'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
