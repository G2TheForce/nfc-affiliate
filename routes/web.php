<?php

use App\Domain\Store\ProductImages;
use App\Http\Controllers\Payments\PaymentReturnController;
use App\Http\Controllers\ReferralRedirectController;
use App\Http\Controllers\WebManifestController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The React SPA handles every non-API path. Named routes below exist so
// framework redirects (guest login, unverified email) resolve to SPA pages.
Route::view('/login', 'app')->name('login');
Route::view('/verify-email', 'app')->name('verification.notice');
Route::view('/reset-password', 'app')->name('password.reset');

// Install manifest for the storefront as a phone app (PWA).
Route::get('/manifest.webmanifest', WebManifestController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->name('manifest');

// Public referral links: record the click, then redirect to an internal store path.
Route::get('/ref/{code}/{link?}', ReferralRedirectController::class)
    ->where(['code' => '[A-Za-z0-9-]{1,60}', 'link' => '[A-Za-z0-9-]{1,60}'])
    // No session or CSRF cookie: a click (or a bot) should not create a session row.
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->name('referral.redirect');

// eSewa and Khalti send the customer back here. Each visit triggers a
// server-side status check; the redirect itself proves nothing.
Route::middleware('throttle:30,1')->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->prefix('payments')->name('payments.')->group(function () {
        Route::get('esewa/return', [PaymentReturnController::class, 'esewaReturn'])->name('esewa.return');
        Route::get('esewa/failure/{attempt}', [PaymentReturnController::class, 'esewaFailure'])->whereUlid('attempt')->name('esewa.failure');
        Route::get('khalti/return', [PaymentReturnController::class, 'khaltiReturn'])->name('khalti.return');
    });

// Product photos for the public store (no session, cacheable).
Route::get('/media/products/{file}', fn (string $file, ProductImages $images) => $images->serve($file))
    ->where('file', '[0-9A-Z]{26}\\.(jpg|png|webp)')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->name('media.product');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|up$|sanctum/).*$')
    ->name('spa');
