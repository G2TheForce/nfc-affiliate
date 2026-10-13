import { useState, type FormEvent } from 'react';
import { Alert, Button, Card, Modal, StatusBadge, TextArea } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useFormErrors } from '@/lib/useFormErrors';

export interface AccountSummary {
    status: 'active' | 'disabled';
    email_verified: boolean;
    last_login_at: string | null;
    signed_in_devices: number;
    is_staff: boolean;
}

type Quick = 'password-reset' | 'sign-out' | 'verification';

const DONE: Record<Quick, string> = {
    'password-reset': 'A password reset link was emailed to the member.',
    'sign-out': 'The member was signed out of every device.',
    verification: 'A new verification email was sent.',
};

/**
 * Sign-in access for one affiliate (affiliates.suspend). Staff never see or
 * set a password: they email a reset link instead.
 */
export function AffiliateAccountCard<D extends { id: string; account: AccountSummary | null }>({
    detail,
    canManage,
    onChange,
}: {
    detail: D;
    canManage: boolean;
    onChange: (d: D) => void;
}) {
    const account = detail.account;
    const [busy, setBusy] = useState<Quick | 'status' | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [statusOpen, setStatusOpen] = useState(false);
    const [reason, setReason] = useState('');
    const { fieldErrors, message, capture, reset } = useFormErrors();

    if (!account) return null;
    const active = account.status === 'active';
    const manageable = canManage && !account.is_staff;

    const run = async (action: Quick) => {
        reset();
        setNotice(null);
        setBusy(action);
        try {
            const res = await api<{ data: D }>(`/admin/affiliates/${detail.id}/account/${action}`, { method: 'POST' });
            onChange(res.data);
            setNotice(DONE[action]);
        } catch (err) {
            capture(err);
        } finally {
            setBusy(null);
        }
    };

    const changeStatus = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setNotice(null);
        setBusy('status');
        try {
            const res = await api<{ data: D }>(`/admin/affiliates/${detail.id}/account/status`, {
                method: 'POST',
                body: { status: active ? 'disabled' : 'active', reason },
            });
            onChange(res.data);
            setStatusOpen(false);
            setReason('');
            setNotice(active ? 'Sign-in is blocked and the member was signed out everywhere.' : 'The member can sign in again.');
        } catch (err) {
            capture(err);
        } finally {
            setBusy(null);
        }
    };

    return (
        <Card title="Sign-in and password">
            <div className="space-y-4">
                {notice && <Alert tone="success">{notice}</Alert>}
                {!statusOpen && (message || fieldErrors.account) && <Alert tone="error">{fieldErrors.account ?? message}</Alert>}
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted">Sign-in</dt>
                        <dd>
                            <StatusBadge status={account.status} label={active ? 'Allowed' : 'Blocked'} />
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted">Email</dt>
                        <dd className="text-ink">{account.email_verified ? 'Verified' : 'Not verified'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted">Last signed in</dt>
                        <dd className="text-ink">{account.last_login_at ? formatDateTime(account.last_login_at) : 'Never'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted">Signed-in devices</dt>
                        <dd className="text-ink">{account.signed_in_devices}</dd>
                    </div>
                </dl>
                {account.is_staff && <p className="text-sm text-muted">This person is also staff. Manage their sign-in on the Staff screen.</p>}
                {manageable && (
                    <div className="flex flex-wrap gap-2">
                        {active && (
                            <Button variant="secondary" loading={busy === 'password-reset'} onClick={() => run('password-reset')}>
                                Email password reset link
                            </Button>
                        )}
                        {active && !account.email_verified && (
                            <Button variant="secondary" loading={busy === 'verification'} onClick={() => run('verification')}>
                                Resend verification email
                            </Button>
                        )}
                        {account.signed_in_devices > 0 && (
                            <Button variant="secondary" loading={busy === 'sign-out'} onClick={() => run('sign-out')}>
                                Sign out everywhere
                            </Button>
                        )}
                        <Button variant="secondary" onClick={() => (reset(), setStatusOpen(true))}>
                            {active ? 'Block sign-in' : 'Allow sign-in'}
                        </Button>
                    </div>
                )}
            </div>

            <Modal title={active ? 'Block sign-in?' : 'Allow sign-in?'} open={statusOpen} onClose={() => setStatusOpen(false)}>
                <form onSubmit={changeStatus} className="space-y-4" noValidate>
                    <p className="text-sm text-muted">
                        {active
                            ? 'The member is signed out everywhere at once and cannot sign in until allowed again. Their membership, referral links and balance are not changed; use Suspend for that.'
                            : 'The member can sign in again with their existing password.'}
                    </p>
                    {(message || fieldErrors.status || fieldErrors.account) && <Alert tone="error">{fieldErrors.status ?? fieldErrors.account ?? message}</Alert>}
                    <TextArea label="Reason (staff only, kept in the audit log)" value={reason} onChange={(e) => setReason(e.target.value)} error={fieldErrors.reason} required />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" loading={busy === 'status'}>
                            {active ? 'Block sign-in' : 'Allow sign-in'}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setStatusOpen(false)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </Modal>
        </Card>
    );
}
