import { useState } from 'react';
import { Navigate } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { Alert, Button, Spinner } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { api, ApiError } from '@/lib/api';

export default function VerifyEmailPage() {
    const { user, loading, refresh, logout } = useAuth();
    const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
    const [sending, setSending] = useState(false);

    if (loading) return <Spinner />;
    if (!user) return <Navigate to="/login" replace />;
    if (user.email_verified) return <Navigate to={homePathFor(user)} replace />;

    const resend = async () => {
        setSending(true);
        try {
            await api('/auth/email/verification-notification', { method: 'POST' });
            setNotice({ tone: 'success', text: 'A new verification link is on its way.' });
        } catch (e) {
            setNotice({ tone: 'error', text: e instanceof ApiError ? e.message : 'Could not send the link.' });
        } finally {
            setSending(false);
        }
    };

    return (
        <AuthLayout title="Verify your email" subtitle={`We sent a link to ${user.email}. Open it to continue.`}>
            <div className="space-y-4">
                {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
                <Button onClick={() => refresh()} className="w-full">
                    I've verified my email
                </Button>
                <Button variant="secondary" onClick={resend} loading={sending} className="w-full">
                    Send the link again
                </Button>
                <Button variant="secondary" onClick={() => logout()} className="w-full">
                    Log out
                </Button>
            </div>
        </AuthLayout>
    );
}
