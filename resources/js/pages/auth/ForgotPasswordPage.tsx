import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { Alert, Button, Field } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { api } from '@/lib/api';
import { useFormErrors } from '@/lib/useFormErrors';

export default function ForgotPasswordPage() {
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSubmitting(true);
        try {
            const res = await api<{ message: string }>('/auth/forgot-password', { method: 'POST', body: { email } });
            setSent(res.message);
        } catch (error) {
            capture(error);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title="Reset your password" subtitle="We'll email you a link to choose a new password.">
            {sent ? (
                <Alert tone="success">{sent}</Alert>
            ) : (
                <form onSubmit={onSubmit} className="space-y-4" noValidate>
                    {message && <Alert tone="error">{message}</Alert>}
                    <Field label="Email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={fieldErrors.email} />
                    <Button type="submit" loading={submitting} className="w-full">
                        Send reset link
                    </Button>
                </form>
            )}
            <p className="mt-6 text-sm">
                <Link to="/login" className="text-brand-600 hover:underline">
                    Back to log in
                </Link>
            </p>
        </AuthLayout>
    );
}
