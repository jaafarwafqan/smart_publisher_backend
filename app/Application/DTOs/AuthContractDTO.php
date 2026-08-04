<?php

namespace App\Application\DTOs;

class AuthContractDTO
{
    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public readonly string $message,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $tokenType,
        public readonly string $scope,
        public readonly array $roles,
        public readonly array $permissions,
    ) {}
}
