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
        // true: the person who approved a payout cannot also confirm it as
        // paid. Off by default for small finance teams; turn on with two or
        // more finance staff.
        'require_separate_payer' => (bool) env('AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER', false),
    ],

    // Fraud signals (spec section 15). Each crossing raises a flag for staff
    // review; nothing is held or blocked automatically.
    'fraud' => [
        // Orders per unique click over the last 30 days, in percent.
        'conversion_max_percent' => (int) env('AFFILIATE_FRAUD_CONVERSION_MAX_PERCENT', 30),
        'conversion_min_orders' => (int) env('AFFILIATE_FRAUD_CONVERSION_MIN_ORDERS', 5),
        // Unique clicks in 30 days with no paid order at all.
        'clicks_without_orders' => (int) env('AFFILIATE_FRAUD_CLICKS_WITHOUT_ORDERS', 1000),
        // Share of paid referred orders refunded over the last 90 days, in percent.
        'refund_rate_max_percent' => (int) env('AFFILIATE_FRAUD_REFUND_RATE_MAX_PERCENT', 30),
        'refund_rate_min_orders' => (int) env('AFFILIATE_FRAUD_REFUND_RATE_MIN_ORDERS', 5),
        // Commission in the last 7 days against the weekly average of the 8 weeks before.
        'earnings_spike_multiple' => (int) env('AFFILIATE_FRAUD_EARNINGS_SPIKE_MULTIPLE', 5),
        'earnings_spike_min_minor' => (int) env('AFFILIATE_FRAUD_EARNINGS_SPIKE_MIN_MINOR', 1000000), // NPR 10,000
        // A payout request at or above this amount.
        'large_payout_minor' => (int) env('AFFILIATE_FRAUD_LARGE_PAYOUT_MINOR', 5000000), // NPR 50,000
        // A payout request within this many days of replacing payout details.
        'detail_change_days' => (int) env('AFFILIATE_FRAUD_DETAIL_CHANGE_DAYS', 7),
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
