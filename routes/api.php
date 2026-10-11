<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Admin\AffiliateController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\PingController;
use App\Http\Controllers\Api\V1\Admin\TermsController;
use App\Http\Controllers\Api\V1\Affiliate\AffiliateMembershipController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\NotificationController;
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

                Route::get('terms', [TermsController::class, 'index'])
                    ->middleware('permission:'.Permission::AffiliatesView->value)->name('terms.index');
                Route::post('terms', [TermsController::class, 'store'])
                    ->middleware('permission:'.Permission::SettingsManage->value)->name('terms.store');
            });
    });
});
