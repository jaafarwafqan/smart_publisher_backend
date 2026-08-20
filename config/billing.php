<?php

return [
    /*
    | Stripe is intentionally configured entirely through the deployment
    | secret store. A blank key disables checkout rather than offering a
    | misleading payment button. Each paid plan stores its Stripe Price ID in
    | plans.stripe_price_id; pricing itself remains a business decision, not
    | fabricated application data.
    */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com'),
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    'return_url' => env('BILLING_RETURN_URL', rtrim((string) env('FRONTEND_URL', ''), '/').'/billing'),
];
