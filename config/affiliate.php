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
        // Encrypted first-party cookie holding the click id, and a random
        // visitor id used only to tell unique clicks from repeats.
        'cookie' => 'nfc_ref',
        'visitor_cookie' => 'nfc_vid',
        'unique_click_hours' => 24,
        // Above this, clicks from one IP still redirect but are not recorded.
        'clicks_per_minute_per_ip' => (int) env('AFFILIATE_CLICKS_PER_MINUTE_PER_IP', 30),
    ],

    'applications' => [
        // A rejected applicant may apply again after this many days.
        'reapply_cooldown_days' => (int) env('AFFILIATE_REAPPLY_COOLDOWN_DAYS', 30),
        'referral_code_length' => 8,
    ],

    'commissions' => [
        'holding_period_days' => (int) env('AFFILIATE_HOLDING_PERIOD_DAYS', 14),
        // true: commissions join the available balance as soon as the holding
        // period passes. false: finance releases approved commissions by hand.
        'auto_release' => (bool) env('AFFILIATE_COMMISSION_AUTO_RELEASE', true),
        // Upper bound for a percentage rule (5000 = 50%), a guard against typos.
        'max_rate_bp' => (int) env('AFFILIATE_COMMISSION_MAX_RATE_BP', 5000),
        'multi_level_enabled' => false,
    ],

    'payouts' => [
        'minimum_minor' => (int) env('AFFILIATE_PAYOUT_MINIMUM_MINOR', 100000), // NPR 1,000
    ],

    'privacy' => [
        // Referral clicks and audit IPs are stored only as keyed hashes.
        'click_retention_days' => (int) env('AFFILIATE_CLICK_RETENTION_DAYS', 395),
        // Visitor and IP hashes on clicks are cleared after this many days.
        'click_metadata_retention_days' => (int) env('AFFILIATE_CLICK_METADATA_RETENTION_DAYS', 90),
    ],

    'store' => [
        'order_number_prefix' => env('STORE_ORDER_NUMBER_PREFIX', 'NFC'),
        // Flat delivery charge in minor units until a shipping provider is connected.
        'shipping_flat_minor' => (int) env('STORE_SHIPPING_FLAT_MINOR', 0),
        'max_quantity_per_line' => 100,
    ],

    // Single-page app URL used in emailed links (verification, password reset).
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    'auth' => [
        'login_attempts_per_minute' => (int) env('AUTH_LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],

];
