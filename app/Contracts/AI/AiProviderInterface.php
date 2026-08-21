<?php

namespace App\Contracts\AI;

use App\Enums\AiOperation;
use App\Enums\AiTone;

/**
 * Provider boundary for text generation.  The application layer never knows
 * whether the configured provider is OpenAI-compatible, self-hosted, or a
 * future vendor.
 */
interface AiProviderInterface
{
    public function name(): string;

    /**
     * @param  list<string>  $platforms
     */
    public function generate(
        AiOperation $operation,
        string $text,
        AiTone $tone,
        ?string $targetLanguage = null,
        array $platforms = [],
    ): string;
}
