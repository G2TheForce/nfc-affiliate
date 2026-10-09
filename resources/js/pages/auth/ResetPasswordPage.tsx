import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Alert, Button, Field } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { api } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';

export default function ResetPasswordPage() {
    const [params] = useSearchParams();
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [done, setDone] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSubmitting(true);
        try {
            await api('/auth/reset-password', {
                method: 'POST',
                body: { token, email, password, password_confirmation: confirmation },
            });
            setDone(true);
        } catch (error) {
            capture(error);
        } finally {
            setSubmitting(false);
        }
    };

    if (!token || !email) {
        return (
            <AuthLayout title="Reset link incomplete">
                <Alert tone="error">This reset link is missing information. Request a new one.</Alert>
                <p className="mt-6 text-sm">
                    <Link to="/forgot-password" className="text-brand-600 hover:underline">
                        Request a new link
                    </Link>
                </p>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Choose a new password" subtitle={email}>
            {done ? (
                <>
                    <Alert tone="success">Your password has been changed. You have been signed out of other devices.</Alert>
                    <p className="mt-6 text-sm">
                        <Link to="/login" className="text-brand-600 hover:underline">
                            Log in
                        </Link>
                    </p>
                </>
            ) : (
                <form onSubmit={onSubmit} className="space-y-4" noValidate>
                    {(message || fieldErrors.email) && <Alert tone="error">{message ?? fieldErrors.email}</Alert>}
                    <Field
                        label="New password"
                        type="password"
                        autoComplete="new-password"
                        required
                        hint="At least 12 characters with upper and lower case letters and a number."
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={fieldErrors.password}
                    />
                    <Field label="Confirm new password" type="password" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
                    <Button type="submit" loading={submitting} className="w-full">
                        Change password
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
