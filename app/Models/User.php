<?php

namespace App\Models;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Notifications\ApiPasswordResetNotification;
use App\Notifications\ApiVerifyEmailNotification;
use App\Support\Tenancy\PersonalOrganizationProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'role', 'branch_id', 'current_organization_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'sanctum';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted at rest for the same reason SocialAccount's OAuth
            // tokens are: a database dump alone must not be enough to
            // generate valid codes for someone else's account.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function mediaAttachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /** @return HasMany<DataDeletionRequest, $this> */
    public function dataDeletionRequests(): HasMany
    {
        return $this->hasMany(DataDeletionRequest::class);
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function organizations(): HasManyThrough
    {
        return $this->hasManyThrough(
            Organization::class,
            OrganizationMembership::class,
            'user_id',
            'id',
            'id',
            'organization_id',
        );
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function membershipFor(int $organizationId): ?OrganizationMembership
    {
        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();
    }

    public function isMemberOf(int $organizationId): bool
    {
        return $this->membershipFor($organizationId) !== null;
    }

    public function roleIn(int $organizationId): ?OrganizationRole
    {
        return $this->membershipFor($organizationId)?->role;
    }

    public function hasOrganizationPermission(?int $organizationId, OrganizationPermission $permission): bool
    {
        if ($organizationId === null) {
            return false;
        }

        return $this->roleIn($organizationId)?->hasPermission($permission) ?? false;
    }

    /**
     * Sprint 4 (Commercial SaaS): the base Authenticatable class this model
     * extends already provides CanResetPassword (and therefore a working
     * Password::sendResetLink()/Password::reset() broker) for free — only
     * the notification itself needs overriding, since the framework
     * default links to a `password.reset` web route this API-only backend
     * doesn't have.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ApiPasswordResetNotification($token));
    }

    /**
     * Same reasoning as sendPasswordResetNotification(): the base
     * MustVerifyEmail trait's default notification links to a
     * `verification.verify` web route this API-only backend doesn't have.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new ApiVerifyEmailNotification);
    }

    /**
     * A user created with no active TenantContext (fresh registration,
     * seeding, test factories) gets their own personal organization as
     * owner — every user needs at least one organization to operate in.
     * A user created *within* an authenticated admin's request (inviting a
     * teammate — TenantContext is already set by ResolveTenantContext
     * middleware at that point) skips this: UserController::store adds them
     * to the inviting admin's organization explicitly instead, so they don't
     * end up with a redundant empty org of their own.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (app(TenantContext::class)->has()) {
                return;
            }

            PersonalOrganizationProvisioner::provision($user);
        });
    }
}
