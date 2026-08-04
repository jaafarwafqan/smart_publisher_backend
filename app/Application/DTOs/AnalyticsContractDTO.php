<?php

namespace App\Application\DTOs;

class AnalyticsContractDTO
{
    public function __construct(
        public readonly int $totalPosts,
        public readonly int $published,
        public readonly int $failed,
        public readonly int $scheduled,
        public readonly int $draft,
        public readonly float $engagementScore,
        public readonly string $engagementTrend,
    ) {}
}
