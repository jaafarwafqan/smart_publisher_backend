<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class PlatformOrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $organization = $this->organization();
        $owner = $organization->relationLoaded('primaryOwner') ? $organization->primaryOwner : null;
        $ownerUser = $owner instanceof OrganizationMembership && $owner->relationLoaded('user') ? $owner->user : null;

        return [
            'id' => (int) $organization->id,
            'name' => (string) $organization->name,
            'status' => (string) $organization->status,
            'primary_owner' => $ownerUser ? [
                'id' => (int) $ownerUser->id,
                'name' => (string) $ownerUser->name,
                'email' => (string) $ownerUser->email,
            ] : null,
            // Explicit rather than left for the frontend to infer from
            // primary_owner being null — this is the exact ambiguity the
            // 2026-08-12 audit flagged: a null owner in the payload could
            // mean "not eager-loaded" as easily as "actually missing", and
            // the UI was defaulting to a vague fallback string either way.
            'primary_owner_missing' => $ownerUser === null,
            'members_count' => (int) ($organization->members_count ?? 0),
            'created_at' => $this->timestamp($organization->created_at),
            'updated_at' => $this->timestamp($organization->updated_at),
            'last_activity_at' => $this->timestamp($organization->last_activity_at),
        ];
    }

    private function organization(): Organization
    {
        if (! $this->resource instanceof Organization) {
            throw new LogicException('PlatformOrganizationResource requires an Organization model.');
        }

        return $this->resource;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) ? Carbon::parse($value)->toIso8601String() : null;
    }
}
