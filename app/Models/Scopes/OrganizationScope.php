<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the active
 * TenantContext's organization. Throws (via TenantContext::get()) rather
 * than silently scoping to nothing/everything if no context is set — see
 * TenantContext's docblock for why that's the safe default.
 *
 * Bypass only via Model::withoutGlobalScope(self::class), and only at
 * explicitly audited system-level call sites (e.g.
 * ProcessScheduledPostsJob's own cross-tenant due-post scan) — grep for
 * withoutGlobalScope(OrganizationScope::class) to find every sanctioned
 * exception.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('organization_id'), app(TenantContext::class)->get());
    }
}
