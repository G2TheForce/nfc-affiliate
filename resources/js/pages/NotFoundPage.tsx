import { Link } from 'react-router';
import { AuthLayout } from '@/layouts/AuthLayout';

export default function NotFoundPage() {
    return (
        <AuthLayout title="Page not found" subtitle="The page you asked for does not exist.">
            <Link to="/" className="text-sm text-brand-600 hover:underline">
                Go to the start page
            </Link>
        </AuthLayout>
    );
}
