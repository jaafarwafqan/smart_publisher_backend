<?php

namespace App\Exceptions\Api;

use Exception;

class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $errors
     */
    public function __construct(
        string $message = 'Application error',
        private readonly ?array $errors = null,
        private readonly int $status = 400,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }
}
