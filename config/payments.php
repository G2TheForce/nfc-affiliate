<?php

/*
|--------------------------------------------------------------------------
| Online payment providers (ADR 0011)
|--------------------------------------------------------------------------
|
| A provider is offered at checkout only when it is enabled and has its
| credentials. Sandbox mode talks to the provider's test servers and is
| refused when APP_ENV=production, so test payments can never mark real
| orders paid. Payments are always confirmed by a server-side status check,
| never by the browser redirect alone.
|
*/

return [

    'providers' => [

        'esewa' => [
            'enabled' => (bool) env('ESEWA_ENABLED', false),
            'mode' => env('ESEWA_MODE', 'sandbox'), // sandbox | production
            'product_code' => env('ESEWA_PRODUCT_CODE'),
            'secret_key' => env('ESEWA_SECRET_KEY'),
            'urls' => [
                'sandbox' => [
                    'form' => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
                    'status' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
                ],
                'production' => [
                    'form' => 'https://epay.esewa.com.np/api/epay/main/v2/form',
                    'status' => 'https://esewa.com.np/api/epay/transaction/status/',
                ],
            ],
        ],

        'khalti' => [
            'enabled' => (bool) env('KHALTI_ENABLED', false),
            'mode' => env('KHALTI_MODE', 'sandbox'),
            'secret_key' => env('KHALTI_SECRET_KEY'),
            'urls' => [
                'sandbox' => 'https://dev.khalti.com/api/v2/epayment/',
                'production' => 'https://khalti.com/api/v2/epayment/',
            ],
            // Khalti refuses amounts below NPR 10.
            'minimum_minor' => 1000,
        ],

    ],

    // Seconds to wait for a provider API before treating the check as failed
    // (the attempt stays pending and is checked again later).
    'http_timeout' => (int) env('PAYMENTS_HTTP_TIMEOUT', 15),

    // Unfinished attempts are re-checked on this schedule (minutes after the
    // previous check), then given up after `give_up_after_hours`.
    'recheck_minutes' => [2, 5, 15, 30, 60, 180, 360],
    'give_up_after_hours' => 48,

];
