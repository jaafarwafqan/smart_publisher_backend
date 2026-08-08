<?php

namespace App\Http\Resources;

use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use LogicException;

class PlatformUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user();

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_active' => (bool) $user->is_active,
            'is_super_admin' => $user->isSuperAdmin(),
            'memberships' => $this->memberships($user),
            'created_at' => $this->timestamp($user->created_at),
            'last_login_at' => $this->timestamp($user->last_login_at),
            'updated_at' => $this->timestamp($user->updated_at),
        ];
    }

    private function user(): User
    {
        if (! $this->resource instanceof User) {
            throw new LogicException('PlatformUserResource requires a User model.');
        }

        return $this->resource;
    }

    /** @return array<int, array<string, mixed>> */
    private function memberships(User $user): array
    {
        if (! $user->relationLoaded('memberships')) {
            return [];
        }

        $memberships = $user->getRelation('memberships');
        if (! $memberships instanceof Collection) {
            return [];
        }

        return $memberships
            ->filter(fn ($membership) => $membership instanceof OrganizationMembership && $membership->relationLoaded('organization'))
            ->map(fn (OrganizationMembership $membership) => [
                'organization' => [
                    'id' => (int) $membership->organization->id,
                    'name' => (string) $membership->organization->name,
                    'status' => (string) $membership->organization->status,
                ],
                'role' => $membership->role->value,
                'status' => (string) $membership->status,
            ])
            ->values()
            ->all();
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
