import { useState, type FormEvent } from 'react';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Card, Field } from '@/components/ui';
import { api } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';

export default function ProfilePage() {
    const { user, refresh } = useAuth();
    const [details, setDetails] = useState({ name: user?.name ?? '', phone: user?.phone ?? '' });
    const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [savedDetails, setSavedDetails] = useState(false);
    const [savedPassword, setSavedPassword] = useState(false);
    const [saving, setSaving] = useState<'details' | 'password' | null>(null);
    const detailErrors = useFormErrors();
    const passwordErrors = useFormErrors();

    const saveDetails = async (e: FormEvent) => {
        e.preventDefault();
        detailErrors.reset();
        setSavedDetails(false);
        setSaving('details');
        try {
            await api('/auth/me', { method: 'PATCH', body: { name: details.name, phone: details.phone.trim() || null } });
            await refresh();
            setSavedDetails(true);
        } catch (err) {
            detailErrors.capture(err);
        } finally {
            setSaving(null);
        }
    };

    const savePassword = async (e: FormEvent) => {
        e.preventDefault();
        passwordErrors.reset();
        setSavedPassword(false);
        setSaving('password');
        try {
            await api('/auth/password', { method: 'POST', body: passwords });
            setPasswords({ current_password: '', password: '', password_confirmation: '' });
            setSavedPassword(true);
        } catch (err) {
            passwordErrors.capture(err);
        } finally {
            setSaving(null);
        }
    };

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">My profile</h1>
            <Card title="Your details">
                <form onSubmit={saveDetails} className="max-w-md space-y-3">
                    {detailErrors.message && <Alert tone="error">{detailErrors.message}</Alert>}
                    {savedDetails && <Alert tone="success">Your details are saved.</Alert>}
                    <Field label="Full name" autoComplete="name" value={details.name} onChange={(e) => setDetails((d) => ({ ...d, name: e.target.value }))} error={detailErrors.fieldErrors.name} />
                    <Field
                        label="Phone (optional)"
                        type="tel"
                        autoComplete="tel"
                        value={details.phone}
                        onChange={(e) => setDetails((d) => ({ ...d, phone: e.target.value }))}
                        error={detailErrors.fieldErrors.phone}
                        hint="With country code, for example +9779812345678."
                    />
                    <div className="space-y-1 text-sm">
                        <p className="font-medium text-ink">Email</p>
                        <p className="text-muted">{user?.email}. To change it, contact us: your email is how you get back into your account.</p>
                    </div>
                    <Button type="submit" loading={saving === 'details'}>
                        Save details
                    </Button>
                </form>
            </Card>
            <Card title="Change password">
                <form onSubmit={savePassword} className="max-w-md space-y-3">
                    {passwordErrors.message && <Alert tone="error">{passwordErrors.message}</Alert>}
                    {savedPassword && <Alert tone="success">Your password is changed. Other devices have been signed out.</Alert>}
                    <Field
                        label="Current password"
                        type="password"
                        autoComplete="current-password"
                        value={passwords.current_password}
                        onChange={(e) => setPasswords((p) => ({ ...p, current_password: e.target.value }))}
                        error={passwordErrors.fieldErrors.current_password}
                    />
                    <Field
                        label="New password"
                        type="password"
                        autoComplete="new-password"
                        value={passwords.password}
                        onChange={(e) => setPasswords((p) => ({ ...p, password: e.target.value }))}
                        error={passwordErrors.fieldErrors.password}
                        hint="At least 12 characters, with upper and lower case letters and a number."
                    />
                    <Field
                        label="Repeat new password"
                        type="password"
                        autoComplete="new-password"
                        value={passwords.password_confirmation}
                        onChange={(e) => setPasswords((p) => ({ ...p, password_confirmation: e.target.value }))}
                        error={passwordErrors.fieldErrors.password_confirmation}
                    />
                    <Button type="submit" loading={saving === 'password'}>
                        Change password
                    </Button>
                </form>
            </Card>
        </div>
    );
}
