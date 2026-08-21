<?php

namespace Tests;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\QuotaGates;
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

    /**
     * 2026-08 feature-gates review: approval workflows, the audit log,
     * branches, and full analytics all moved behind QuotaGates' boolean
     * feature keys — every organization defaults to the Free plan, which
     * has all four false (see DefaultPlans::free()). Any test exercising
     * one of those surfaces on a freshly-created $user's organization needs
     * this first, or it now correctly gets 403'd by the new gate instead of
     * reaching the behavior the test actually means to exercise.
     *
     * Every other gate (numeric and boolean) keeps its QuotaGates fallback
     * value, so this never accidentally loosens an unrelated quota a test
     * wasn't asking about.
     */
    protected function grantFeatures(User $user, string ...$features): void
    {
        $plan = Plan::query()->create([
            'name' => 'Test plan ('.implode(', ', $features).')',
            'slug' => 'test-features-'.uniqid(),
            'limits' => array_replace(
                QuotaGates::fallbackAll(),
                array_fill_keys($features, true),
            ),
        ]);

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $user->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active'],
        );
    }
}
