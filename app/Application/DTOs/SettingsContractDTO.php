<?php

namespace App\Application\DTOs;

class SettingsContractDTO
{
    /**
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $timezone,
        public readonly string $dateFormat,
        public readonly string $timeFormat,
        public readonly array $features,
    ) {}
}
