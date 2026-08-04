<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model (Post, SocialAccount, SocialPage,
 * MediaAttachment, PostMetric). Two things happen automatically:
 *  1. Every query is scoped to the active TenantContext's organization.
 *  2. organization_id is stamped from TenantContext on create, so it is
 *     never possible to accidentally create a tenant-owned row without one
 *     (and never possible for a caller to smuggle in a different
 *     organization_id via mass-assignment — this trait always overwrites
 *     whatever was set before the model saves).
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model): void {
            $model->organization_id = app(TenantContext::class)->get();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
