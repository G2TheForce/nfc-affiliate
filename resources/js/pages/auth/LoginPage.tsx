import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { Alert, Button, Field } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';
import { useFormErrors } from '@/lib/useFormErrors';

export default function LoginPage() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const { fieldErrors, message, capture, reset } = useFormErrors();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const onSubmit = async (e: FormEvent) => {
        e.preventDefault();
        reset();
        setSubmitting(true);
        try {
            const user = await login(email, password);
            const from = (location.state as { from?: string } | null)?.from;
            navigate(!user.email_verified ? '/verify-email' : from ?? homePathFor(user), { replace: true });
        } catch (error) {
            capture(error);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title="Log in" subtitle="Affiliates, customers and staff sign in here.">
            <form onSubmit={onSubmit} className="space-y-4" noValidate>
                {message && <Alert tone="error">{message}</Alert>}
                <Field label="Email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={fieldErrors.email} />
                <Field
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={fieldErrors.password}
                />
                <Button type="submit" loading={submitting} className="w-full">
                    Log in
                </Button>
            </form>
            <div className="mt-6 flex flex-col gap-2 text-sm sm:flex-row sm:justify-between">
                <Link to="/forgot-password" className="text-brand-600 hover:underline">
                    Forgot password?
                </Link>
                <Link to="/register" className="text-brand-600 hover:underline">
                    Create an account
                </Link>
            </div>
        </AuthLayout>
    );
}
