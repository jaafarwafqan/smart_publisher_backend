<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => (string) ($this['message'] ?? ''),
            'access_token' => (string) ($this['access_token'] ?? ''),
            'refresh_token' => (string) ($this['refresh_token'] ?? ''),
            'expires_in' => (int) ($this['expires_in'] ?? 0),
            'token_type' => (string) ($this['token_type'] ?? 'Bearer'),
            'scope' => (string) ($this['scope'] ?? ''),
            'user' => new UserResource($this['user']),
            'roles' => array_values((array) ($this['roles'] ?? [])),
            'permissions' => array_values((array) ($this['permissions'] ?? [])),
        ];
    }
}
