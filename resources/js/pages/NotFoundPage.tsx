import { Link } from 'react-router';
import { Illustration } from '@/components/brand';
import { AuthLayout } from '@/layouts/AuthLayout';

export default function NotFoundPage() {
    return (
        <AuthLayout title="Page not found" subtitle="The page you asked for does not exist.">
            <Illustration kind="lost" className="mx-auto h-28 w-28" />
            <Link to="/" className="mt-4 block text-center text-sm font-semibold text-brand-600 hover:underline">
                Go to the start page
            </Link>
        </AuthLayout>
    );
}
