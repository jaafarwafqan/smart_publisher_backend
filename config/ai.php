<?php

return [
    'provider' => env('AI_PROVIDER', 'openai-compatible'),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
    'max_text_characters' => (int) env('AI_MAX_TEXT_CHARACTERS', 10000),
    'providers' => [
        'openai_compatible' => [
            'endpoint' => env('AI_OPENAI_COMPATIBLE_ENDPOINT'),
            'api_key' => env('AI_OPENAI_COMPATIBLE_API_KEY'),
            'model' => env('AI_OPENAI_COMPATIBLE_MODEL'),
        ],
    ],
];
