<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\PingController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
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

        // Staff area: verified email + admin.access, then a specific permission per route.
        Route::prefix('admin')->name('admin.')
            ->middleware(['verified', 'permission:'.Permission::AdminAccess->value])
            ->group(function () {
                Route::get('ping', PingController::class)->name('ping');
                Route::get('audit-logs', [AuditLogController::class, 'index'])
                    ->middleware('permission:'.Permission::AuditLogsView->value)
                    ->name('audit-logs.index');
            });
    });
});
