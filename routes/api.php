<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Admin\AdjustmentController;
use App\Http\Controllers\Api\V1\Admin\AffiliateController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CommissionController;
use App\Http\Controllers\Api\V1\Admin\CommissionPlanController;
use App\Http\Controllers\Api\V1\Admin\CommissionRuleController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\PingController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ReconciliationController;
use App\Http\Controllers\Api\V1\Admin\ReferralReportController as AdminReferralReportController;
use App\Http\Controllers\Api\V1\Admin\TermsController;
use App\Http\Controllers\Api\V1\Affiliate\AffiliateMembershipController;
use App\Http\Controllers\Api\V1\Affiliate\EarningsController;
use App\Http\Controllers\Api\V1\Affiliate\ReferralLinkController;
use App\Http\Controllers\Api\V1\Affiliate\ReferralReportController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\CustomerOrderController;
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
        Route::get('products/{slug}', [CatalogController::class, 'product'])
            ->where('slug', '[a-z0-9-]+')->name('products.show');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
        Route::get('referral', [CheckoutController::class, 'referral'])->name('referral');
        Route::post('cart/quote', [CheckoutController::class, 'quote'])->name('cart.quote');
        Route::post('orders', [CheckoutController::class, 'store'])
            ->middleware('throttle:checkout')->name('orders.store');
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
                Route::post('links', [ReferralLinkController::class, 'store'])->middleware('throttle:30,1')->name('links.store');
                Route::patch('links/{link}', [ReferralLinkController::class, 'update'])->whereUlid('link')->name('links.update');
                Route::get('referrals/summary', [ReferralReportController::class, 'summary'])->name('referrals.summary');
                Route::get('referrals/orders', [ReferralReportController::class, 'orders'])->name('referrals.orders');
                Route::get('earnings/summary', [EarningsController::class, 'summary'])->name('earnings.summary');
                Route::get('earnings/commissions', [EarningsController::class, 'commissions'])->name('earnings.commissions');
                Route::get('earnings/ledger', [EarningsController::class, 'ledger'])->name('earnings.ledger');
                Route::get('earnings/rates', [EarningsController::class, 'rates'])->name('earnings.rates');
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

                Route::prefix('orders')->name('orders.')->group(function () {
                    Route::get('/', [OrderController::class, 'index'])->middleware('permission:'.Permission::OrdersView->value)->name('index');
                    Route::get('{order}', [OrderController::class, 'show'])->middleware('permission:'.Permission::OrdersView->value)->name('show');
                    Route::post('{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:'.Permission::OrdersManage->value)->name('cancel');
                    Route::post('{order}/attribution', [OrderController::class, 'attribute'])->middleware('permission:'.Permission::AttributionManual->value)->name('attribution');
                    Route::post('{order}/payments', [OrderController::class, 'confirmPayment'])->middleware('permission:'.Permission::PaymentsConfirm->value)->name('payments.store');
                    Route::post('{order}/refunds', [OrderController::class, 'refund'])->middleware('permission:'.Permission::RefundsRecord->value)->name('refunds.store');
                });

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

                Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware($commissionsView)->name('adjustments.index');
                Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware('permission:'.Permission::CommissionsAdjust->value)->name('adjustments.store');
                Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->middleware($approve)->name('adjustments.approve');
                Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->middleware($approve)->name('adjustments.reject');

                Route::get('reconciliation', [ReconciliationController::class, 'index'])->middleware('permission:'.Permission::ReconciliationView->value)->name('reconciliation.index');
                Route::post('reconciliation', [ReconciliationController::class, 'store'])->middleware(['permission:'.Permission::ReconciliationView->value, 'throttle:6,1'])->name('reconciliation.store');

                Route::get('referrals/summary', [AdminReferralReportController::class, 'summary'])
                    ->middleware('permission:'.Permission::ReportsView->value)->name('referrals.summary');

                Route::get('terms', [TermsController::class, 'index'])
                    ->middleware('permission:'.Permission::AffiliatesView->value)->name('terms.index');
                Route::post('terms', [TermsController::class, 'store'])
                    ->middleware('permission:'.Permission::SettingsManage->value)->name('terms.store');
            });
    });
});
