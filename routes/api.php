<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Admin\AdjustmentController;
use App\Http\Controllers\Api\V1\Admin\AffiliateController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CampaignController;
use App\Http\Controllers\Api\V1\Admin\CommissionController;
use App\Http\Controllers\Api\V1\Admin\CommissionPlanController;
use App\Http\Controllers\Api\V1\Admin\CommissionRuleController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\FraudFlagController;
use App\Http\Controllers\Api\V1\Admin\MarketingAssetController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\OrderOperationsController;
use App\Http\Controllers\Api\V1\Admin\PaymentAttemptController;
use App\Http\Controllers\Api\V1\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\PingController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ReconciliationController;
use App\Http\Controllers\Api\V1\Admin\ReferralReportController as AdminReferralReportController;
use App\Http\Controllers\Api\V1\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\TermsController;
use App\Http\Controllers\Api\V1\Affiliate\AffiliateMembershipController;
use App\Http\Controllers\Api\V1\Affiliate\CampaignController as AffiliateCampaignController;
use App\Http\Controllers\Api\V1\Affiliate\DashboardController;
use App\Http\Controllers\Api\V1\Affiliate\EarningsController;
use App\Http\Controllers\Api\V1\Affiliate\MarketingAssetController as AffiliateMarketingAssetController;
use App\Http\Controllers\Api\V1\Affiliate\PayoutController;
use App\Http\Controllers\Api\V1\Affiliate\ReferralLinkController;
use App\Http\Controllers\Api\V1\Affiliate\ReferralReportController;
use App\Http\Controllers\Api\V1\Affiliate\ReportController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Store\AffiliateProgrammeController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\CustomerOrderController;
use App\Http\Controllers\Api\V1\Store\PaymentController as StorePaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public authentication endpoints
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register')->name('register');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login')->name('login');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:password-reset')->name('forgot-password');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])
            ->middleware('throttle:password-reset')->name('reset-password');

        // Signed link from the verification email.
        Route::get('verify-email/{user}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])->name('verify-email');
    });

    // Public store. Signed-in customers are recognised from the session or
    // token when present; guests can check out too.
    Route::prefix('store')->name('store.')->middleware('throttle:store')->group(function () {
        Route::get('products', [CatalogController::class, 'products'])->name('products.index');
        Route::get('affiliate-programme', [AffiliateProgrammeController::class, 'show'])->name('affiliate-programme');
        Route::get('products/{slug}', [CatalogController::class, 'product'])
            ->where('slug', '[a-z0-9-]+')->name('products.show');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
        Route::get('referral', [CheckoutController::class, 'referral'])->name('referral');
        Route::post('cart/quote', [CheckoutController::class, 'quote'])->name('cart.quote');
        Route::post('orders', [CheckoutController::class, 'store'])
            ->middleware('throttle:checkout')->name('orders.store');
        // Online payment (ADR 0011): the customer's own order, by session or checkout access token.
        Route::get('payment-methods', [StorePaymentController::class, 'methods'])->name('payment-methods');
        Route::post('orders/{order}/payments', [StorePaymentController::class, 'start'])
            ->whereUlid('order')->middleware('throttle:checkout')->name('orders.payments.start');
        Route::get('orders/{order}/payment-status', [StorePaymentController::class, 'status'])
            ->whereUlid('order')->name('orders.payments.status');
    });

    // Authenticated endpoints
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')->name('verification.send');
        });

        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('{id}/read', [NotificationController::class, 'markRead'])->whereUuid('id')->name('read');
        });

        Route::get('orders', [CustomerOrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [CustomerOrderController::class, 'show'])->whereUlid('order')->name('orders.show');

        // Affiliate self-service: always the signed-in user's own profile.
        Route::prefix('affiliate')->name('affiliate.')->middleware('verified')->group(function () {
            Route::get('terms/current', [AffiliateMembershipController::class, 'currentTerms'])->name('terms.current');
            Route::post('terms/accept', [AffiliateMembershipController::class, 'acceptTerms'])->name('terms.accept');
            Route::post('applications', [AffiliateMembershipController::class, 'apply'])
                ->middleware('throttle:6,1')->name('applications.store');
            Route::get('profile', [AffiliateMembershipController::class, 'show'])->name('profile.show');
            Route::patch('profile', [AffiliateMembershipController::class, 'update'])->name('profile.update');
            Route::post('profile/close', [AffiliateMembershipController::class, 'close'])
                ->middleware('throttle:6,1')->name('profile.close');

            Route::middleware('permission:'.Permission::AffiliatePortal->value)->group(function () {
                Route::get('links', [ReferralLinkController::class, 'index'])->name('links.index');
                Route::get('campaigns', [AffiliateCampaignController::class, 'index'])->name('campaigns.index');
                Route::get('marketing-assets', [AffiliateMarketingAssetController::class, 'index'])->name('marketing-assets.index');
                Route::get('marketing-assets/{asset}/file', [AffiliateMarketingAssetController::class, 'file'])
                    ->whereUlid('asset')->middleware('throttle:120,1')->name('marketing-assets.file');
                Route::post('links', [ReferralLinkController::class, 'store'])->middleware('throttle:30,1')->name('links.store');
                Route::patch('links/{link}', [ReferralLinkController::class, 'update'])->whereUlid('link')->name('links.update');
                Route::get('referrals/summary', [ReferralReportController::class, 'summary'])->name('referrals.summary');
                Route::get('referrals/orders', [ReferralReportController::class, 'orders'])->name('referrals.orders');
                Route::get('earnings/summary', [EarningsController::class, 'summary'])->name('earnings.summary');
                Route::get('earnings/commissions', [EarningsController::class, 'commissions'])->name('earnings.commissions');
                Route::get('earnings/ledger', [EarningsController::class, 'ledger'])->name('earnings.ledger');
                Route::get('earnings/rates', [EarningsController::class, 'rates'])->name('earnings.rates');
                Route::get('dashboard', DashboardController::class)->name('dashboard');
                Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
                Route::get('reports/{report}/export', [ReportController::class, 'export'])
                    ->where('report', '[a-z-]+')->middleware('throttle:exports')->name('reports.export');
                Route::get('payouts/summary', [PayoutController::class, 'summary'])->name('payouts.summary');
                Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
            });

            Route::middleware('permission:'.Permission::AffiliatePayoutsRequest->value)->group(function () {
                Route::post('payouts', [PayoutController::class, 'store'])->middleware('throttle:6,1')->name('payouts.store');
                Route::post('payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->whereUlid('payout')->name('payouts.cancel');
                Route::put('payout-method', [PayoutController::class, 'saveMethod'])->middleware('throttle:6,1')->name('payout-method.update');
            });
        });

        // Staff area: verified email + admin.access, then a specific permission per route.
        Route::prefix('admin')->name('admin.')
            ->middleware(['verified', 'permission:'.Permission::AdminAccess->value])
            ->group(function () {
                Route::get('ping', PingController::class)->name('ping');
                Route::get('audit-logs', [AuditLogController::class, 'index'])
                    ->middleware('permission:'.Permission::AuditLogsView->value)
                    ->name('audit-logs.index');

                Route::prefix('affiliates')->name('affiliates.')->group(function () {
                    $view = 'permission:'.Permission::AffiliatesView->value;
                    $review = 'permission:'.Permission::AffiliatesReview->value;
                    $suspend = 'permission:'.Permission::AffiliatesSuspend->value;

                    Route::get('/', [AffiliateController::class, 'index'])->middleware($view)->name('index');
                    Route::get('counts', [AffiliateController::class, 'counts'])->middleware($view)->name('counts');
                    Route::get('{affiliate}', [AffiliateController::class, 'show'])->middleware($view)->name('show');
                    Route::post('{affiliate}/approve', [AffiliateController::class, 'approve'])->middleware($review)->name('approve');
                    Route::post('{affiliate}/reject', [AffiliateController::class, 'reject'])->middleware($review)->name('reject');
                    Route::post('{affiliate}/notes', [AffiliateController::class, 'addNote'])->middleware($review)->name('notes.store');
                    Route::post('{affiliate}/suspend', [AffiliateController::class, 'suspend'])->middleware($suspend)->name('suspend');
                    Route::post('{affiliate}/reinstate', [AffiliateController::class, 'reinstate'])->middleware($suspend)->name('reinstate');
                    Route::post('{affiliate}/deactivate', [AffiliateController::class, 'deactivate'])->middleware($suspend)->name('deactivate');
                    Route::post('{affiliate}/reactivate', [AffiliateController::class, 'reactivate'])->middleware($suspend)->name('reactivate');
                });

                $catalog = 'permission:'.Permission::CatalogManage->value;
                $campaigns = 'permission:'.Permission::CampaignsManage->value;
                Route::get('products', [ProductController::class, 'index'])
                    ->middleware('permission:'.Permission::CatalogManage->value.'|'.Permission::CampaignsManage->value)->name('products.index');
                Route::post('products', [ProductController::class, 'store'])->middleware($catalog)->name('products.store');
                Route::patch('products/{product}', [ProductController::class, 'update'])->middleware($catalog)->name('products.update');
                Route::get('categories', [ProductController::class, 'categories'])->middleware($catalog)->name('categories.index');
                Route::post('categories', [ProductController::class, 'storeCategory'])->middleware($catalog)->name('categories.store');

                Route::get('coupons', [CouponController::class, 'index'])->middleware($campaigns)->name('coupons.index');
                Route::post('coupons', [CouponController::class, 'store'])->middleware($campaigns)->name('coupons.store');
                Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->middleware($campaigns)->name('coupons.update');

                Route::get('campaigns', [CampaignController::class, 'index'])
                    ->middleware($campaigns.'|'.Permission::CommissionRulesManage->value.'|'.Permission::CommissionsView->value)->name('campaigns.index');
                Route::post('campaigns', [CampaignController::class, 'store'])->middleware($campaigns)->name('campaigns.store');
                Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereUlid('campaign')
                    ->middleware($campaigns.'|'.Permission::CommissionRulesManage->value.'|'.Permission::CommissionsView->value)->name('campaigns.show');
                Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->whereUlid('campaign')->middleware($campaigns)->name('campaigns.update');

                $admins = 'permission:'.Permission::AdminsManage->value;
                Route::get('staff', [StaffController::class, 'index'])->middleware($admins)->name('staff.index');
                Route::post('staff', [StaffController::class, 'store'])->middleware([$admins, 'throttle:20,1'])->name('staff.store');
                Route::post('staff/{user}/invitation', [StaffController::class, 'resendInvitation'])->whereUlid('user')->middleware([$admins, 'throttle:10,1'])->name('staff.invitation');
                Route::post('staff/{user}/status', [StaffController::class, 'status'])->whereUlid('user')->middleware($admins)->name('staff.status');

                $settings = 'permission:'.Permission::SettingsManage->value;
                Route::get('settings', [SettingsController::class, 'index'])->middleware($settings)->name('settings.index');
                Route::patch('settings', [SettingsController::class, 'update'])->middleware($settings)->name('settings.update');
                Route::delete('settings/{key}', [SettingsController::class, 'reset'])->where('key', '[a-z_.]+')->middleware($settings)->name('settings.reset');

                $assets = 'permission:'.Permission::MarketingAssetsManage->value;
                Route::get('marketing-assets', [MarketingAssetController::class, 'index'])->middleware($assets)->name('marketing-assets.index');
                Route::post('marketing-assets', [MarketingAssetController::class, 'store'])->middleware([$assets, 'throttle:30,1'])->name('marketing-assets.store');
                Route::patch('marketing-assets/{asset}', [MarketingAssetController::class, 'update'])->middleware($assets)->name('marketing-assets.update');
                Route::delete('marketing-assets/{asset}', [MarketingAssetController::class, 'destroy'])->middleware($assets)->name('marketing-assets.destroy');
                Route::get('marketing-assets/{asset}/file', [MarketingAssetController::class, 'file'])->middleware($assets)->name('marketing-assets.file');

                Route::prefix('orders')->name('orders.')->group(function () {
                    Route::get('/', [OrderController::class, 'index'])->middleware('permission:'.Permission::OrdersView->value)->name('index');
                    Route::get('{order}', [OrderController::class, 'show'])->middleware('permission:'.Permission::OrdersView->value)->name('show');
                    Route::post('{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:'.Permission::OrdersManage->value)->name('cancel');
                    Route::post('{order}/attribution', [OrderController::class, 'attribute'])->middleware('permission:'.Permission::AttributionManual->value)->name('attribution');
                    Route::post('{order}/payments', [OrderController::class, 'confirmPayment'])->middleware('permission:'.Permission::PaymentsConfirm->value)->name('payments.store');
                    Route::post('{order}/refunds', [OrderController::class, 'refund'])->middleware('permission:'.Permission::RefundsRecord->value)->name('refunds.store');
                    Route::post('{order}/fulfilment', [OrderOperationsController::class, 'fulfil'])->middleware('permission:'.Permission::OrdersManage->value)->name('fulfilment');
                    Route::post('{order}/disputes', [OrderOperationsController::class, 'openDispute'])->middleware('permission:'.Permission::RefundsRecord->value)->name('disputes.store');
                });
                Route::post('disputes/{dispute}/resolve', [OrderOperationsController::class, 'resolveDispute'])
                    ->whereUlid('dispute')->middleware('permission:'.Permission::RefundsRecord->value)->name('disputes.resolve');

                Route::get('payment-attempts', [PaymentAttemptController::class, 'index'])->middleware('permission:'.Permission::OrdersView->value)->name('payment-attempts.index');
                Route::post('payment-attempts/{attempt}/recheck', [PaymentAttemptController::class, 'recheck'])
                    ->whereUlid('attempt')->middleware(['permission:'.Permission::PaymentsConfirm->value, 'throttle:20,1'])->name('payment-attempts.recheck');
                Route::post('payment-attempts/{attempt}/resolve', [PaymentAttemptController::class, 'resolve'])
                    ->whereUlid('attempt')->middleware('permission:'.Permission::PaymentsConfirm->value)->name('payment-attempts.resolve');

                $rules = 'permission:'.Permission::CommissionRulesManage->value;
                $commissionsView = 'permission:'.Permission::CommissionsView->value;
                $fraud = 'permission:'.Permission::FraudReview->value;
                $approve = 'permission:'.Permission::CommissionsApprove->value;

                Route::get('commission-rules', [CommissionRuleController::class, 'index'])->middleware($rules.'|'.Permission::CommissionsView->value)->name('commission-rules.index');
                Route::get('commission-rules/preview', [CommissionRuleController::class, 'preview'])->middleware($rules.'|'.Permission::CommissionsView->value)->name('commission-rules.preview');
                Route::post('commission-rules', [CommissionRuleController::class, 'store'])->middleware($rules)->name('commission-rules.store');
                Route::patch('commission-rules/{rule}', [CommissionRuleController::class, 'update'])->middleware($rules)->name('commission-rules.update');
                Route::get('commission-plans', [CommissionPlanController::class, 'index'])->middleware($rules.'|'.Permission::CommissionsView->value)->name('commission-plans.index');
                Route::post('commission-plans', [CommissionPlanController::class, 'store'])->middleware($rules)->name('commission-plans.store');
                Route::patch('commission-plans/{plan}', [CommissionPlanController::class, 'update'])->middleware($rules)->name('commission-plans.update');
                Route::post('affiliates/{affiliate}/plan', [CommissionPlanController::class, 'assign'])->middleware($rules)->name('affiliates.plan');
                Route::get('affiliates/{affiliate}/ledger', [CommissionController::class, 'affiliateLedger'])->middleware($commissionsView)->name('affiliates.ledger');

                Route::prefix('commissions')->name('commissions.')->group(function () use ($commissionsView, $fraud, $approve) {
                    Route::get('/', [CommissionController::class, 'index'])->middleware($commissionsView)->name('index');
                    Route::get('summary', [CommissionController::class, 'summary'])->middleware($commissionsView)->name('summary');
                    Route::get('{commission}', [CommissionController::class, 'show'])->whereUlid('commission')->middleware($commissionsView)->name('show');
                    Route::post('{commission}/hold', [CommissionController::class, 'hold'])->middleware($fraud)->name('hold');
                    Route::post('{commission}/release-hold', [CommissionController::class, 'releaseHold'])->middleware($fraud)->name('release-hold');
                    Route::post('{commission}/void', [CommissionController::class, 'void'])->middleware($fraud)->name('void');
                    Route::post('{commission}/release', [CommissionController::class, 'release'])->middleware($approve)->name('release');
                });

                Route::get('fraud-flags', [FraudFlagController::class, 'index'])->middleware($fraud)->name('fraud-flags.index');
                Route::post('fraud-flags/{flag}/review', [FraudFlagController::class, 'review'])->whereUlid('flag')->middleware($fraud)->name('fraud-flags.review');

                Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware($commissionsView)->name('adjustments.index');
                Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware('permission:'.Permission::CommissionsAdjust->value)->name('adjustments.store');
                Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->middleware($approve)->name('adjustments.approve');
                Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->middleware($approve)->name('adjustments.reject');

                Route::prefix('payouts')->name('payouts.')->group(function () {
                    $view = 'permission:'.Permission::PayoutsView->value;
                    $review = 'permission:'.Permission::PayoutsReview->value;
                    $pay = 'permission:'.Permission::PayoutsMarkPaid->value;

                    Route::get('/', [AdminPayoutController::class, 'index'])->middleware($view)->name('index');
                    Route::get('summary', [AdminPayoutController::class, 'summary'])->middleware($view)->name('summary');
                    Route::get('{payout}', [AdminPayoutController::class, 'show'])->whereUlid('payout')->middleware($view)->name('show');
                    Route::post('{payout}/approve', [AdminPayoutController::class, 'approve'])->whereUlid('payout')->middleware($review)->name('approve');
                    Route::post('{payout}/reject', [AdminPayoutController::class, 'reject'])->whereUlid('payout')->middleware($review)->name('reject');
                    Route::post('{payout}/mark-paid', [AdminPayoutController::class, 'markPaid'])->whereUlid('payout')->middleware($pay)->name('mark-paid');
                    Route::post('{payout}/mark-failed', [AdminPayoutController::class, 'markFailed'])->whereUlid('payout')->middleware($pay)->name('mark-failed');
                });

                Route::get('reconciliation', [ReconciliationController::class, 'index'])->middleware('permission:'.Permission::ReconciliationView->value)->name('reconciliation.index');
                Route::post('reconciliation', [ReconciliationController::class, 'store'])->middleware(['permission:'.Permission::ReconciliationView->value, 'throttle:6,1'])->name('reconciliation.store');

                Route::get('dashboard', [AdminDashboardController::class, 'admin'])
                    ->middleware('permission:'.Permission::ReportsView->value)->name('dashboard');
                Route::get('dashboard/filter-options', [AdminDashboardController::class, 'filterOptions'])
                    ->middleware('permission:'.Permission::ReportsView->value)->name('dashboard.filter-options');
                Route::get('finance/dashboard', [AdminDashboardController::class, 'finance'])
                    ->middleware('permission:'.Permission::PayoutsView->value.'|'.Permission::CommissionsView->value)->name('finance.dashboard');
                Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
                Route::get('reports/{report}', [AdminReportController::class, 'show'])->where('report', '[a-z-]+')->name('reports.show');
                Route::get('reports/{report}/export', [AdminReportController::class, 'export'])
                    ->where('report', '[a-z-]+')->middleware('throttle:exports')->name('reports.export');

                Route::get('referrals/summary', [AdminReferralReportController::class, 'summary'])
                    ->middleware('permission:'.Permission::ReportsView->value)->name('referrals.summary');

                Route::get('terms', [TermsController::class, 'index'])
                    ->middleware('permission:'.Permission::AffiliatesView->value)->name('terms.index');
                Route::post('terms', [TermsController::class, 'store'])
                    ->middleware('permission:'.Permission::SettingsManage->value)->name('terms.store');
            });
    });
});
