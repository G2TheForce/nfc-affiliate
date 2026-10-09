import { Navigate, Route, Routes } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { Spinner } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import AccountHomePage from '@/pages/account/AccountHomePage';
import AdminHomePage from '@/pages/admin/AdminHomePage';
import AuditLogPage from '@/pages/admin/AuditLogPage';
import EmailVerifiedPage from '@/pages/auth/EmailVerifiedPage';
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage';
import LoginPage from '@/pages/auth/LoginPage';
import RegisterPage from '@/pages/auth/RegisterPage';
import ResetPasswordPage from '@/pages/auth/ResetPasswordPage';
import VerifyEmailPage from '@/pages/auth/VerifyEmailPage';
import NotFoundPage from '@/pages/NotFoundPage';
import { GuestOnly, RequireAuth } from '@/routes/guards';

function Home() {
    const { user, loading } = useAuth();
    if (loading) return <Spinner />;
    return <Navigate to={user ? homePathFor(user) : '/login'} replace />;
}

export default function App() {
    return (
        <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/login" element={<GuestOnly><LoginPage /></GuestOnly>} />
            <Route path="/register" element={<GuestOnly><RegisterPage /></GuestOnly>} />
            <Route path="/forgot-password" element={<GuestOnly><ForgotPasswordPage /></GuestOnly>} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/verify-email" element={<VerifyEmailPage />} />
            <Route path="/email-verified" element={<EmailVerifiedPage />} />

            <Route
                path="/account"
                element={
                    <RequireAuth>
                        <AppLayout area="My account" nav={[{ to: '/account', label: 'Overview' }]} />
                    </RequireAuth>
                }
            >
                <Route index element={<AccountHomePage />} />
            </Route>

            <Route
                path="/admin"
                element={
                    <RequireAuth staff>
                        <AppLayout
                            area="Staff"
                            nav={[
                                { to: '/admin', label: 'Dashboard' },
                                { to: '/admin/audit-logs', label: 'Audit log', permission: 'audit_logs.view' },
                            ]}
                        />
                    </RequireAuth>
                }
            >
                <Route index element={<AdminHomePage />} />
                <Route path="audit-logs" element={<AuditLogPage />} />
            </Route>

            <Route path="*" element={<NotFoundPage />} />
        </Routes>
    );
}
