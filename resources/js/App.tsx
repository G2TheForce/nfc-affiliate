import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router';
import { AppLayout } from '@/layouts/AppLayout';
import { StoreLayout } from '@/layouts/StoreLayout';
const AccountHomePage = lazy(() => import('@/pages/account/AccountHomePage'));
const AffiliateDashboardPage = lazy(() => import('@/pages/account/AffiliateDashboardPage'));
const AffiliatePage = lazy(() => import('@/pages/account/AffiliatePage'));
const EarningsPage = lazy(() => import('@/pages/account/EarningsPage'));
const PayoutsPage = lazy(() => import('@/pages/account/PayoutsPage'));
const CustomerOrdersPage = lazy(() => import('@/pages/account/OrdersPage'));
const ReferralLinksPage = lazy(() => import('@/pages/account/ReferralLinksPage'));
const ReferralStatsPage = lazy(() => import('@/pages/account/ReferralStatsPage'));
const AdjustmentsPage = lazy(() => import('@/pages/admin/AdjustmentsPage'));
const AdminPayoutsPage = lazy(() => import('@/pages/admin/PayoutsPage'));
const FinanceDashboardPage = lazy(() => import('@/pages/admin/FinanceDashboardPage'));
const ReportsPage = lazy(() => import('@/pages/admin/ReportsPage'));
const CommissionRulesPage = lazy(() => import('@/pages/admin/CommissionRulesPage'));
const CommissionsPage = lazy(() => import('@/pages/admin/CommissionsPage'));
const CouponsPage = lazy(() => import('@/pages/admin/CouponsPage'));
const ReconciliationPage = lazy(() => import('@/pages/admin/ReconciliationPage'));
const OrderDetailPage = lazy(() => import('@/pages/admin/OrderDetailPage'));
const AdminOrdersPage = lazy(() => import('@/pages/admin/OrdersPage'));
const ProductsPage = lazy(() => import('@/pages/admin/ProductsPage'));
const ReferralsPage = lazy(() => import('@/pages/admin/ReferralsPage'));
const AffiliateProgrammePage = lazy(() => import('@/pages/store/AffiliateProgrammePage'));
const CartPage = lazy(() => import('@/pages/store/CartPage'));
const CheckoutPage = lazy(() => import('@/pages/store/CheckoutPage'));
const OrderConfirmedPage = lazy(() => import('@/pages/store/OrderConfirmedPage'));
const ProductPage = lazy(() => import('@/pages/store/ProductPage'));
const ShopPage = lazy(() => import('@/pages/store/ShopPage'));
const AffiliateDetailPage = lazy(() => import('@/pages/admin/AffiliateDetailPage'));
const AffiliatesListPage = lazy(() => import('@/pages/admin/AffiliatesListPage'));
const TermsPage = lazy(() => import('@/pages/admin/TermsPage'));
const NotificationsPage = lazy(() => import('@/pages/shared/NotificationsPage'));
const AdminHomePage = lazy(() => import('@/pages/admin/AdminHomePage'));
const AuditLogPage = lazy(() => import('@/pages/admin/AuditLogPage'));
const FraudPage = lazy(() => import('@/pages/admin/FraudPage'));
const CampaignsPage = lazy(() => import('@/pages/admin/CampaignsPage'));
const StaffPage = lazy(() => import('@/pages/admin/StaffPage'));
const SettingsPage = lazy(() => import('@/pages/admin/SettingsPage'));
const ProfilePage = lazy(() => import('@/pages/shared/ProfilePage'));
const AdminMarketingPage = lazy(() => import('@/pages/admin/MarketingPage'));
const MarketingPage = lazy(() => import('@/pages/account/MarketingPage'));
const EmailVerifiedPage = lazy(() => import('@/pages/auth/EmailVerifiedPage'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/ForgotPasswordPage'));
const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'));
const VerifyEmailPage = lazy(() => import('@/pages/auth/VerifyEmailPage'));
const NotFoundPage = lazy(() => import('@/pages/NotFoundPage'));
import { GuestOnly, RequireAuth } from '@/routes/guards';
import { Spinner } from '@/components/ui';

export default function App() {
    return (
        // Each page loads on first visit, so the storefront does not download the admin screens.
        <Suspense fallback={<Spinner />}>
            <Routes>
                <Route element={<StoreLayout />}>
                    <Route path="/" element={<ShopPage />} />
                    <Route path="/shop" element={<ShopPage />} />
                    <Route path="/shop/:slug" element={<ProductPage />} />
                    <Route path="/cart" element={<CartPage />} />
                    <Route path="/affiliates" element={<AffiliateProgrammePage />} />
                    <Route path="/checkout" element={<CheckoutPage />} />
                    <Route path="/order-confirmed" element={<OrderConfirmedPage />} />
                </Route>
                <Route
                    path="/login"
                    element={
                        <GuestOnly>
                            <LoginPage />
                        </GuestOnly>
                    }
                />
                <Route
                    path="/register"
                    element={
                        <GuestOnly>
                            <RegisterPage />
                        </GuestOnly>
                    }
                />
                <Route
                    path="/forgot-password"
                    element={
                        <GuestOnly>
                            <ForgotPasswordPage />
                        </GuestOnly>
                    }
                />
                <Route path="/reset-password" element={<ResetPasswordPage />} />
                <Route path="/verify-email" element={<VerifyEmailPage />} />
                <Route path="/email-verified" element={<EmailVerifiedPage />} />

                <Route
                    path="/account"
                    element={
                        <RequireAuth>
                            <AppLayout
                                area="My account"
                                notificationsPath="/account/notifications"
                                nav={[
                                    { to: '/account', label: 'Overview' },
                                    {
                                        to: '/account/orders',
                                        label: 'My orders',
                                    },
                                    {
                                        to: '/account/profile',
                                        label: 'My profile',
                                    },
                                    {
                                        to: '/account/affiliate',
                                        label: 'Affiliate programme',
                                    },
                                    {
                                        to: '/account/affiliate/dashboard',
                                        label: 'Affiliate dashboard',
                                        permission: 'affiliate.portal',
                                    },
                                    {
                                        to: '/account/affiliate/links',
                                        label: 'Referral links',
                                        permission: 'affiliate.portal',
                                    },
                                    {
                                        to: '/account/affiliate/marketing',
                                        label: 'Marketing materials',
                                        permission: 'affiliate.portal',
                                    },
                                    {
                                        to: '/account/affiliate/referrals',
                                        label: 'Referral stats',
                                        permission: 'affiliate.portal',
                                    },
                                    {
                                        to: '/account/earnings',
                                        label: 'Earnings',
                                        permission: 'affiliate.portal',
                                    },
                                    {
                                        to: '/account/payouts',
                                        label: 'Payouts',
                                        permission: 'affiliate.portal',
                                    },
                                    { to: '/', label: 'Shop' },
                                ]}
                            />
                        </RequireAuth>
                    }
                >
                    <Route index element={<AccountHomePage />} />
                    <Route path="orders" element={<CustomerOrdersPage />} />
                    <Route path="affiliate" element={<AffiliatePage />} />
                    <Route path="affiliate/dashboard" element={<AffiliateDashboardPage />} />
                    <Route path="profile" element={<ProfilePage />} />
                    <Route path="affiliate/links" element={<ReferralLinksPage />} />
                    <Route path="affiliate/marketing" element={<MarketingPage />} />
                    <Route path="affiliate/referrals" element={<ReferralStatsPage />} />
                    <Route path="earnings" element={<EarningsPage />} />
                    <Route path="payouts" element={<PayoutsPage />} />
                    <Route path="notifications" element={<NotificationsPage />} />
                </Route>

                <Route
                    path="/admin"
                    element={
                        <RequireAuth staff>
                            <AppLayout
                                area="Staff"
                                notificationsPath="/admin/notifications"
                                nav={[
                                    { to: '/admin', label: 'Dashboard' },
                                    {
                                        to: '/admin/finance',
                                        label: 'Finance',
                                        permission: ['payouts.view', 'commissions.view'],
                                    },
                                    {
                                        to: '/admin/reports',
                                        label: 'Reports',
                                        permission: ['reports.view', 'commissions.view', 'payouts.view', 'affiliates.view', 'fraud.review'],
                                    },
                                    {
                                        to: '/admin/affiliates',
                                        label: 'Affiliates',
                                        permission: 'affiliates.view',
                                    },
                                    {
                                        to: '/admin/orders',
                                        label: 'Orders',
                                        permission: 'orders.view',
                                    },
                                    {
                                        to: '/admin/commissions',
                                        label: 'Commissions',
                                        permission: 'commissions.view',
                                    },
                                    {
                                        to: '/admin/adjustments',
                                        label: 'Adjustments',
                                        permission: 'commissions.view',
                                    },
                                    {
                                        to: '/admin/payouts',
                                        label: 'Payouts',
                                        permission: 'payouts.view',
                                    },
                                    {
                                        to: '/admin/commission-rules',
                                        label: 'Commission rules',
                                        permission: ['commission_rules.manage', 'commissions.view'],
                                    },
                                    {
                                        to: '/admin/fraud',
                                        label: 'Fraud review',
                                        permission: 'fraud.review',
                                    },
                                    {
                                        to: '/admin/reconciliation',
                                        label: 'Reconciliation',
                                        permission: 'reconciliation.view',
                                    },
                                    {
                                        to: '/admin/referrals',
                                        label: 'Referral report',
                                        permission: 'reports.view',
                                    },
                                    {
                                        to: '/admin/campaigns',
                                        label: 'Campaigns',
                                        permission: 'campaigns.manage',
                                    },
                                    {
                                        to: '/admin/coupons',
                                        label: 'Coupons',
                                        permission: 'campaigns.manage',
                                    },
                                    {
                                        to: '/admin/marketing',
                                        label: 'Marketing materials',
                                        permission: 'marketing_assets.manage',
                                    },
                                    {
                                        to: '/admin/products',
                                        label: 'Products',
                                        permission: 'catalog.manage',
                                    },
                                    {
                                        to: '/admin/terms',
                                        label: 'Affiliate terms',
                                        permission: 'affiliates.view',
                                    },
                                    {
                                        to: '/admin/staff',
                                        label: 'Staff',
                                        permission: 'admins.manage',
                                    },
                                    {
                                        to: '/admin/settings',
                                        label: 'Settings',
                                        permission: 'settings.manage',
                                    },
                                    {
                                        to: '/admin/audit-logs',
                                        label: 'Audit log',
                                        permission: 'audit_logs.view',
                                    },
                                    {
                                        to: '/admin/profile',
                                        label: 'My profile',
                                    },
                                ]}
                            />
                        </RequireAuth>
                    }
                >
                    <Route index element={<AdminHomePage />} />
                    <Route path="finance" element={<FinanceDashboardPage />} />
                    <Route path="reports" element={<ReportsPage />} />
                    <Route path="audit-logs" element={<AuditLogPage />} />
                    <Route path="staff" element={<StaffPage />} />
                    <Route path="profile" element={<ProfilePage />} />
                    <Route path="settings" element={<SettingsPage />} />
                    <Route path="affiliates" element={<AffiliatesListPage />} />
                    <Route path="affiliates/:id" element={<AffiliateDetailPage />} />
                    <Route path="terms" element={<TermsPage />} />
                    <Route path="orders" element={<AdminOrdersPage />} />
                    <Route path="orders/:id" element={<OrderDetailPage />} />
                    <Route path="referrals" element={<ReferralsPage />} />
                    <Route path="commissions" element={<CommissionsPage />} />
                    <Route path="adjustments" element={<AdjustmentsPage />} />
                    <Route path="payouts" element={<AdminPayoutsPage />} />
                    <Route path="commission-rules" element={<CommissionRulesPage />} />
                    <Route path="fraud" element={<FraudPage />} />
                    <Route path="reconciliation" element={<ReconciliationPage />} />
                    <Route path="campaigns" element={<CampaignsPage />} />
                    <Route path="coupons" element={<CouponsPage />} />
                    <Route path="marketing" element={<AdminMarketingPage />} />
                    <Route path="products" element={<ProductsPage />} />
                    <Route path="notifications" element={<NotificationsPage />} />
                </Route>

                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </Suspense>
    );
}
