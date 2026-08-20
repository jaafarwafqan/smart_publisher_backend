<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read string|null $last_activity_at Read-only aggregate selected
 *                                              by platform administration queries.
 */
#[Fillable(['name', 'slug', 'settings', 'status'])]
class Organization extends Model
{
    use HasFactory;

    // 2026-08: platform-admin organization deletion is a soft delete —
    // AdminOrganizationController::destroy() requires status 'inactive'
    // first, then this trait's global scope excludes the row from every
    // normal query afterward without destroying its posts/media/social
    // accounts/memberships. See the migration's own docblock.
    use SoftDeletes;

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

    /**
     * The membership row explicitly designated as this organization's
     * primary owner. Unlike activeOwner() below, this is not inferred on
     * every read — it's a persisted fact kept correct by
     * OrganizationOwnershipService. Prefer this over activeOwner() for
     * anything user-facing (e.g. platform admin panel); activeOwner()
     * remains for existence checks ("does this org have any active owner
     * at all") that don't need the "primary" designation.
     *
     * @return BelongsTo<OrganizationMembership, $this>
     */
    public function primaryOwner(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'primary_owner_id');
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

    /** @return HasOne<OrganizationSubscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class);
    }
}
