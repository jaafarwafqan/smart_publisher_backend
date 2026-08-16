<?php

return [
    'providers' => [
        'facebook' => [
            'client_id' => env('SOCIAL_FACEBOOK_CLIENT_ID'),
            'client_secret' => env('SOCIAL_FACEBOOK_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_FACEBOOK_AUTHORIZE_URL', 'https://www.facebook.com/v20.0/dialog/oauth'),
            'token_url' => env('SOCIAL_FACEBOOK_TOKEN_URL', 'https://graph.facebook.com/v20.0/oauth/access_token'),
            // instagram_basic/instagram_content_publish added 2026-08: a
            // live Instagram publish attempt failed with a real Meta error
            // ("(#10) Application does not have permission for this
            // action") because the token minted from this scope list never
            // requested Instagram permissions at all — pages_show_list is
            // enough to *discover* a linked Instagram Business Account
            // (FacebookOAuthProvider::listPages()) but not to publish to
            // it. Existing connections need to reconnect (Disconnect then
            // Connect again) to mint a token with the new scopes — a scope
            // change alone doesn't retroactively grant permissions to an
            // already-issued token.
            'default_scopes' => [
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
                'instagram_basic',
                'instagram_content_publish',
            ],
        ],
        'instagram' => [
            'client_id' => env('SOCIAL_INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('SOCIAL_INSTAGRAM_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_INSTAGRAM_AUTHORIZE_URL', 'https://api.instagram.com/oauth/authorize'),
            'token_url' => env('SOCIAL_INSTAGRAM_TOKEN_URL', 'https://api.instagram.com/oauth/access_token'),
            'default_scopes' => ['user_profile', 'user_media'],
        ],
        'x' => [
            'client_id' => env('SOCIAL_X_CLIENT_ID'),
            'client_secret' => env('SOCIAL_X_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_X_AUTHORIZE_URL', 'https://twitter.com/i/oauth2/authorize'),
            'token_url' => env('SOCIAL_X_TOKEN_URL', 'https://api.twitter.com/2/oauth2/token'),
            'default_scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],
        ],
        'telegram' => [
            'client_id' => env('SOCIAL_TELEGRAM_CLIENT_ID'),
            'client_secret' => env('SOCIAL_TELEGRAM_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_TELEGRAM_AUTHORIZE_URL'),
            'token_url' => env('SOCIAL_TELEGRAM_TOKEN_URL'),
            'default_scopes' => [],
        ],
        'linkedin' => [
            'client_id' => env('SOCIAL_LINKEDIN_CLIENT_ID'),
            'client_secret' => env('SOCIAL_LINKEDIN_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_LINKEDIN_AUTHORIZE_URL', 'https://www.linkedin.com/oauth/v2/authorization'),
            'token_url' => env('SOCIAL_LINKEDIN_TOKEN_URL', 'https://www.linkedin.com/oauth/v2/accessToken'),
            'default_scopes' => ['openid', 'profile', 'w_member_social'],
        ],
        'whatsapp' => [
            'client_id' => env('SOCIAL_WHATSAPP_CLIENT_ID'),
            'client_secret' => env('SOCIAL_WHATSAPP_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_WHATSAPP_AUTHORIZE_URL', 'https://www.facebook.com/v20.0/dialog/oauth'),
            'token_url' => env('SOCIAL_WHATSAPP_TOKEN_URL', 'https://graph.facebook.com/v20.0/oauth/access_token'),
            'default_scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management'],
        ],
        'youtube' => [
            'client_id' => env('SOCIAL_YOUTUBE_CLIENT_ID'),
            'client_secret' => env('SOCIAL_YOUTUBE_CLIENT_SECRET'),
            'authorize_url' => env('SOCIAL_YOUTUBE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url' => env('SOCIAL_YOUTUBE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'default_scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl'],
        ],
    ],

    'oauth_state_ttl_minutes' => (int) env('SOCIAL_OAUTH_STATE_TTL_MINUTES', 15),
    'default_token_ttl_minutes' => (int) env('SOCIAL_DEFAULT_TOKEN_TTL_MINUTES', 60),

    // CTO audit 4.4: beginOAuthAuthorization() previously accepted ANY
    // syntactically-valid URL as redirect_uri — an attacker able to call
    // this endpoint directly (not through the real app) could redirect the
    // provider's authorization code to a domain they control. The Flutter
    // app only ever sends one fixed value (smartpublisher://oauth/callback,
    // see app_providers.dart), so an exact allowlist costs nothing
    // legitimate and closes the gap.
    'allowed_redirect_uris' => array_filter(array_map(
        'trim',
        explode(',', (string) env('SOCIAL_ALLOWED_REDIRECT_URIS', 'smartpublisher://oauth/callback'))
    )),
];
