import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Button, Field } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { useFormErrors } from '@/lib/useFormErrors';

export default function RegisterPage() {
    const { register } = useAuth();
    const navigate = useNavigate();
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', password_confirmation: '' });
    const [accepted, setAccepted] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: e.target.value });

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSubmitting(true);
        try {
            await register({ ...form, phone: form.phone || undefined, accept_privacy_notice: accepted });
            navigate('/verify-email', { replace: true });
        } catch (error) {
            capture(error);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title="Create your account" subtitle="You can apply to the affiliate programme after verifying your email.">
            <form onSubmit={onSubmit} className="space-y-4" noValidate>
                {message && <Alert tone="error">{message}</Alert>}
                <Field label="Full name" autoComplete="name" required value={form.name} onChange={set('name')} error={fieldErrors.name} />
                <Field label="Email" type="email" autoComplete="email" required value={form.email} onChange={set('email')} error={fieldErrors.email} />
                <Field
                    label="Phone (optional)"
                    type="tel"
                    autoComplete="tel"
                    placeholder="+9779812345678"
                    hint="Include the country code."
                    value={form.phone}
                    onChange={set('phone')}
                    error={fieldErrors.phone}
                />
                <Field
                    label="Password"
                    type="password"
                    autoComplete="new-password"
                    required
                    hint="At least 12 characters with upper and lower case letters and a number."
                    value={form.password}
                    onChange={set('password')}
                    error={fieldErrors.password}
                />
                <Field
                    label="Confirm password"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={form.password_confirmation}
                    onChange={set('password_confirmation')}
                />
                <div>
                    <label className="flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" className="mt-1 h-4 w-4" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                        <span>I have read and accept the privacy notice.</span>
                    </label>
                    {fieldErrors.accept_privacy_notice && <p className="mt-1 text-sm text-danger">{fieldErrors.accept_privacy_notice}</p>}
                </div>
                <Button type="submit" loading={submitting} className="w-full">
                    Create account
                </Button>
            </form>
            <p className="mt-6 text-sm text-muted">
                Already registered?{' '}
                <Link to="/login" className="text-brand-600 hover:underline">
                    Log in
                </Link>
            </p>
        </AuthLayout>
    );
}
