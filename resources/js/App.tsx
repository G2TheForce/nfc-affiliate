import { Route, Routes } from 'react-router';
import { AppLayout } from '@/layouts/AppLayout';
import { StoreLayout } from '@/layouts/StoreLayout';
import AccountHomePage from '@/pages/account/AccountHomePage';
import AffiliateDashboardPage from '@/pages/account/AffiliateDashboardPage';
import AffiliatePage from '@/pages/account/AffiliatePage';
import EarningsPage from '@/pages/account/EarningsPage';
import PayoutsPage from '@/pages/account/PayoutsPage';
import CustomerOrdersPage from '@/pages/account/OrdersPage';
import ReferralLinksPage from '@/pages/account/ReferralLinksPage';
import ReferralStatsPage from '@/pages/account/ReferralStatsPage';
import AdjustmentsPage from '@/pages/admin/AdjustmentsPage';
import AdminPayoutsPage from '@/pages/admin/PayoutsPage';
import FinanceDashboardPage from '@/pages/admin/FinanceDashboardPage';
import ReportsPage from '@/pages/admin/ReportsPage';
import CommissionRulesPage from '@/pages/admin/CommissionRulesPage';
import CommissionsPage from '@/pages/admin/CommissionsPage';
import CouponsPage from '@/pages/admin/CouponsPage';
import ReconciliationPage from '@/pages/admin/ReconciliationPage';
import OrderDetailPage from '@/pages/admin/OrderDetailPage';
import AdminOrdersPage from '@/pages/admin/OrdersPage';
import ProductsPage from '@/pages/admin/ProductsPage';
import ReferralsPage from '@/pages/admin/ReferralsPage';
import AffiliateProgrammePage from '@/pages/store/AffiliateProgrammePage';
import CartPage from '@/pages/store/CartPage';
import CheckoutPage from '@/pages/store/CheckoutPage';
import OrderConfirmedPage from '@/pages/store/OrderConfirmedPage';
import ProductPage from '@/pages/store/ProductPage';
import ShopPage from '@/pages/store/ShopPage';
import AffiliateDetailPage from '@/pages/admin/AffiliateDetailPage';
import AffiliatesListPage from '@/pages/admin/AffiliatesListPage';
import TermsPage from '@/pages/admin/TermsPage';
import NotificationsPage from '@/pages/shared/NotificationsPage';
import AdminHomePage from '@/pages/admin/AdminHomePage';
import AuditLogPage from '@/pages/admin/AuditLogPage';
import FraudPage from '@/pages/admin/FraudPage';
import EmailVerifiedPage from '@/pages/auth/EmailVerifiedPage';
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage';
import LoginPage from '@/pages/auth/LoginPage';
import RegisterPage from '@/pages/auth/RegisterPage';
import ResetPasswordPage from '@/pages/auth/ResetPasswordPage';
import VerifyEmailPage from '@/pages/auth/VerifyEmailPage';
import NotFoundPage from '@/pages/NotFoundPage';
import { GuestOnly, RequireAuth } from '@/routes/guards';

export default function App() {
    return (
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
                        <AppLayout
                            area="My account"
                            notificationsPath="/account/notifications"
                            nav={[
                                { to: '/account', label: 'Overview' },
                                { to: '/account/orders', label: 'My orders' },
                                { to: '/account/affiliate', label: 'Affiliate programme' },
                                { to: '/account/affiliate/dashboard', label: 'Affiliate dashboard', permission: 'affiliate.portal' },
                                { to: '/account/affiliate/links', label: 'Referral links', permission: 'affiliate.portal' },
                                { to: '/account/affiliate/referrals', label: 'Referral stats', permission: 'affiliate.portal' },
                                { to: '/account/earnings', label: 'Earnings', permission: 'affiliate.portal' },
                                { to: '/account/payouts', label: 'Payouts', permission: 'affiliate.portal' },
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
                <Route path="affiliate/links" element={<ReferralLinksPage />} />
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
                                { to: '/admin/finance', label: 'Finance', permission: ['payouts.view', 'commissions.view'] },
                                { to: '/admin/reports', label: 'Reports', permission: ['reports.view', 'commissions.view', 'payouts.view', 'affiliates.view', 'fraud.review'] },
                                { to: '/admin/affiliates', label: 'Affiliates', permission: 'affiliates.view' },
                                { to: '/admin/orders', label: 'Orders', permission: 'orders.view' },
                                { to: '/admin/commissions', label: 'Commissions', permission: 'commissions.view' },
                                { to: '/admin/adjustments', label: 'Adjustments', permission: 'commissions.view' },
                                { to: '/admin/payouts', label: 'Payouts', permission: 'payouts.view' },
                                { to: '/admin/commission-rules', label: 'Commission rules', permission: ['commission_rules.manage', 'commissions.view'] },
                                { to: '/admin/fraud', label: 'Fraud review', permission: 'fraud.review' },
                                { to: '/admin/reconciliation', label: 'Reconciliation', permission: 'reconciliation.view' },
                                { to: '/admin/referrals', label: 'Referral report', permission: 'reports.view' },
                                { to: '/admin/coupons', label: 'Coupons', permission: 'campaigns.manage' },
                                { to: '/admin/products', label: 'Products', permission: 'catalog.manage' },
                                { to: '/admin/terms', label: 'Affiliate terms', permission: 'affiliates.view' },
                                { to: '/admin/audit-logs', label: 'Audit log', permission: 'audit_logs.view' },
                            ]}
                        />
                    </RequireAuth>
                }
            >
                <Route index element={<AdminHomePage />} />
                <Route path="finance" element={<FinanceDashboardPage />} />
                <Route path="reports" element={<ReportsPage />} />
                <Route path="audit-logs" element={<AuditLogPage />} />
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
                <Route path="coupons" element={<CouponsPage />} />
                <Route path="products" element={<ProductsPage />} />
                <Route path="notifications" element={<NotificationsPage />} />
            </Route>

            <Route path="*" element={<NotFoundPage />} />
        </Routes>
    );
}
