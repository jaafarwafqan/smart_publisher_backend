<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'facebook' => [
        'graph_url' => env('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com'),
        // Phase 3 (webhook receiver, 2026-08-16): the arbitrary string this
        // app hands Meta's App Dashboard when subscribing to Page webhooks
        // ("Verify Token" field) — Meta echoes it back on the one-time GET
        // handshake so we can confirm the callback URL is really ours
        // before it starts sending real events. Not a shared secret used to
        // sign anything; X-Hub-Signature-256 verification on the actual
        // POST deliveries uses the App Secret
        // (social.providers.facebook.client_secret) instead, same as the
        // existing OAuth token exchange.
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
    ],

];
