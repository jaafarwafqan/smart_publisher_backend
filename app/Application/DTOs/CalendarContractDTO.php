<?php

namespace App\Application\DTOs;

class CalendarContractDTO
{
    public function __construct(
        public readonly string $id,
        public readonly int $postId,
        public readonly string $title,
        public readonly string $status,
        public readonly string $scheduledAt,
    ) {}
}
