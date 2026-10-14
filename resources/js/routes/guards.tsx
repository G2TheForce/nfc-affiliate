import type { ReactNode } from 'react';
import { Link, Navigate, Outlet, useLocation } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { Alert, Spinner } from '@/components/ui';

export function RequireAuth({ children, staff = false }: { children: ReactNode; staff?: boolean }) {
    const { user, loading } = useAuth();
    const location = useLocation();

    if (loading) return <Spinner />;
    if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    if (!user.email_verified) return <Navigate to="/verify-email" replace />;
    if (staff && !user.is_staff) return <Navigate to="/account" replace />;
    if (!staff && user.is_staff) return <Navigate to="/admin" replace />;

    return <>{children}</>;
}

export function GuestOnly({ children }: { children: ReactNode }) {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    if (user) return <Navigate to={user.email_verified ? homePathFor(user) : '/verify-email'} replace />;
    return <>{children}</>;
}

/**
 * Affiliate tools need an approved membership. Without it the API answers
 * 403, so say why instead of showing the raw permission error.
 */
export function RequireAffiliate() {
    const { can } = useAuth();
    if (can('affiliate.portal')) return <Outlet />;

    return (
        <Alert tone="info">
            Referral tools open once your affiliate application is approved.{' '}
            <Link to="/account/affiliate" className="font-medium underline">
                See your application
            </Link>
        </Alert>
    );
}
