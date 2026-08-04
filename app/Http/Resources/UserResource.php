<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use LogicException;
use Spatie\Permission\Models\Role;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user();

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $user->role,
            'branch' => $this->branchPayload($user),
            'roles' => $this->rolesPayload($user),
            'social_accounts_count' => $this->socialAccountsCount($user),
            'created_at' => $this->isoTimestamp($user->created_at),
            'updated_at' => $this->isoTimestamp($user->updated_at),
        ];
    }

    /**
     * @return array{id: int, name: string, code: string}|null
     */
    private function branchPayload(User $user): ?array
    {
        if (! $user->relationLoaded('branch')) {
            return null;
        }

        $branch = $user->getRelation('branch');
        if (! $branch instanceof Branch) {
            return null;
        }

        return [
            'id' => (int) $branch->id,
            'name' => (string) $branch->name,
            'code' => (string) $branch->code,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, guard_name: string}>
     */
    private function rolesPayload(User $user): array
    {
        if (! $user->relationLoaded('roles')) {
            return [];
        }

        $loadedRoles = $user->getRelation('roles');
        if (! $loadedRoles instanceof Collection) {
            return [];
        }

        $roles = [];
        foreach ($loadedRoles as $role) {
            if (! $role instanceof Role) {
                continue;
            }

            $roles[] = [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'guard_name' => (string) $role->guard_name,
            ];
        }

        return $roles;
    }

    private function socialAccountsCount(User $user): ?int
    {
        if (! $user->relationLoaded('socialAccounts')) {
            return null;
        }

        $accounts = $user->getRelation('socialAccounts');

        return $accounts instanceof Collection ? $accounts->count() : null;
    }

    private function isoTimestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function user(): User
    {
        if (! $this->resource instanceof User) {
            throw new LogicException('UserResource requires a User model.');
        }

        return $this->resource;
    }
}
