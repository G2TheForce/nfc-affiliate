<?php

/*
|--------------------------------------------------------------------------
| Affiliate programme defaults
|--------------------------------------------------------------------------
|
| Business rules live here (and later in the system_settings table) instead
| of being hardcoded in services. Values can be overridden per environment.
|
*/

return [

    // Timezone used for business dates (reports, holding periods, payout
    // schedules). The application itself stores timestamps in UTC.
    'business_timezone' => env('AFFILIATE_BUSINESS_TIMEZONE', 'Asia/Kathmandu'),

    'currency' => [
        'default' => env('AFFILIATE_CURRENCY', 'NPR'),
        // Number of minor units per major unit (NPR 1 = 100 paisa).
        'minor_units' => 100,
    ],

    'attribution' => [
        // last_click: the most recent valid referral click within the window
        // wins; an affiliate coupon entered at checkout overrides a click.
        'policy' => env('AFFILIATE_ATTRIBUTION_POLICY', 'last_click'),
        'window_days' => (int) env('AFFILIATE_ATTRIBUTION_WINDOW_DAYS', 30),
        'coupon_overrides_click' => true,
    ],

    'commissions' => [
        'holding_period_days' => (int) env('AFFILIATE_HOLDING_PERIOD_DAYS', 14),
        'include_shipping' => false,
        'include_tax' => false,
        'multi_level_enabled' => false,
    ],

    'payouts' => [
        'minimum_minor' => (int) env('AFFILIATE_PAYOUT_MINIMUM_MINOR', 100000), // NPR 1,000
    ],

    'privacy' => [
        // Referral clicks and audit IPs are stored only as keyed hashes.
        'click_retention_days' => (int) env('AFFILIATE_CLICK_RETENTION_DAYS', 395),
    ],

    // Single-page app URL used in emailed links (verification, password reset).
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    'auth' => [
        'login_attempts_per_minute' => (int) env('AUTH_LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],

];
