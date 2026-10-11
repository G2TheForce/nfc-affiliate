<?php

use App\Http\Controllers\ReferralRedirectController;
use Illuminate\Support\Facades\Route;

// The React SPA handles every non-API path. Named routes below exist so
// framework redirects (guest login, unverified email) resolve to SPA pages.
Route::view('/login', 'app')->name('login');
Route::view('/verify-email', 'app')->name('verification.notice');
Route::view('/reset-password', 'app')->name('password.reset');

// Public referral links: record the click, then redirect to an internal store path.
Route::get('/ref/{code}/{link?}', ReferralRedirectController::class)
    ->where(['code' => '[A-Za-z0-9-]{1,60}', 'link' => '[A-Za-z0-9-]{1,60}'])
    ->name('referral.redirect');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|up$|sanctum/).*$')
    ->name('spa');
