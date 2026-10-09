<?php

use Illuminate\Support\Facades\Route;

// The React SPA handles every non-API path. Named routes below exist so
// framework redirects (guest login, unverified email) resolve to SPA pages.
Route::view('/login', 'app')->name('login');
Route::view('/verify-email', 'app')->name('verification.notice');
Route::view('/reset-password', 'app')->name('password.reset');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api/|up$|sanctum/).*$')
    ->name('spa');
