<?php

namespace App\Exceptions;

use App\Exceptions\Api\ApiException;

final class AiProviderException extends ApiException
{
    public function __construct(string $message = 'The AI service is temporarily unavailable.')
    {
        parent::__construct($message, ['code' => ['ai_provider_unavailable']], 503);
    }
}
