<?php

namespace App\Application\DTOs;

class NotificationContractDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly bool $read,
        public readonly string $createdAt,
    ) {}
}
