<?php

return [
    'provider' => env('AI_PROVIDER', 'openai-compatible'),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
    'max_text_characters' => (int) env('AI_MAX_TEXT_CHARACTERS', 10000),
    // Bounds the provider's OWN response length via the request itself
    // (max_tokens) rather than only truncating the string afterward —
    // without this, a misbehaving or malicious prompt could make the
    // provider generate an arbitrarily long (slow, costly) response before
    // AIWritingService::sanitizeOutput() ever gets a chance to trim it.
    // ~4 chars/token is a conservative, provider-agnostic rule of thumb;
    // the default therefore tracks max_text_characters rather than being a
    // second, independently-tuned number.
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 3000),
    'providers' => [
        'openai_compatible' => [
            'endpoint' => env('AI_OPENAI_COMPATIBLE_ENDPOINT'),
            'api_key' => env('AI_OPENAI_COMPATIBLE_API_KEY'),
            'model' => env('AI_OPENAI_COMPATIBLE_MODEL'),
        ],
    ],
];
