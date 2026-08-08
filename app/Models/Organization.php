<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read string|null $last_activity_at Read-only aggregate selected
 *                                              by platform administration queries.
 */
#[Fillable(['name', 'slug', 'settings', 'status'])]
class Organization extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasOne<OrganizationMembership, $this> */
    public function activeOwner(): HasOne
    {
        return $this->hasOne(OrganizationMembership::class)
            ->where('role', OrganizationRole::Owner)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->latestOfMany();
    }

    public function members(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            OrganizationMembership::class,
            'organization_id',
            'id',
            'id',
            'user_id',
        );
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<DataDeletionRequest, $this> */
    public function dataDeletionRequests(): HasMany
    {
        return $this->hasMany(DataDeletionRequest::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class);
    }
}
