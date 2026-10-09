import { Link } from 'react-router';
import { Alert } from '@/components/ui';
import { AuthLayout } from '@/layouts/AuthLayout';

export default function EmailVerifiedPage() {
    return (
        <AuthLayout title="Email verified">
            <Alert tone="success">Thanks, your email address is confirmed.</Alert>
            <p className="mt-6 text-sm">
                <Link to="/login" className="text-brand-600 hover:underline">
                    Continue
                </Link>
            </p>
        </AuthLayout>
    );
}
