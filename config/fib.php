<?php

// Published from first-iraqi-bank/fib-laravel-payment-sdk via
// `php artisan vendor:publish --tag=fib-payment-sdk-config` (2026-08-21).
// The auth_accounts.default.{client_id,secret} keys are edited here to
// read FIB_API_KEY/FIB_API_SECRET rather than the SDK's own default
// FIB_CLIENT_ID/FIB_CLIENT_SECRET — the literal env var names this
// product's billing spec standardizes on. No real values are ever
// committed; see .env.example.
return [
    'login' => env('FIB_BASE_URL').'/auth/realms/fib-online-shop/protocol/openid-connect/token',
    'base_url' => env('FIB_BASE_URL', 'https://api.fibpayment.com').'/protected/v1',
    'grant' => env('FIB_GRANT_TYPE', 'client_credentials'),
    'refundable_for' => env('FIB_REFUNDABLE_FOR', 'P7D'),
    'currency' => env('FIB_CURRENCY', 'IQD'),
    'callback' => env('FIB_CALLBACK_URL'),
    'default_auth_account' => env('FIB_DEFAULT_ACCOUNT', 'default'),
    'auth_accounts' => [
        'default' => [
            'client_id' => env('FIB_API_KEY'),
            'secret' => env('FIB_API_SECRET'),
        ],
    ],
];
