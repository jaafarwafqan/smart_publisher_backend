<?php

namespace Tests;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs $callback with TenantContext set to $user's own organization
     * (auto-provisioned on User creation — see User::booted()). Needed by
     * any test that creates a tenant-owned model (Post, SocialAccount,
     * SocialPage, MediaAttachment, PostMetric) directly via ::create()
     * rather than through an HTTP request, since only real requests pass
     * through ResolveTenantContext middleware.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function asOrganizationOf(User $user, callable $callback): mixed
    {
        return app(TenantContext::class)->run((int) $user->current_organization_id, $callback);
    }
}
