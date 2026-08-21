<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\User;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * 2026-08 feature-gates review: approval workflows, the audit log,
 * branches, and full analytics used to be free on every plan — including
 * an organization with no subscription at all. A small organization had no
 * reason to ever upgrade. Every one of these tests proves the SAME
 * organization is rejected with 403 on the default Free plan (see
 * DefaultPlans::free() — all four gates false) and accepted once the
 * matching feature is granted, matching the fail-closed-with-documented-
 * fallback philosophy the numeric quotas already use.
 */
class FeatureGatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_member_is_rejected_with_403_approving_a_post_and_accepted_on_an_enterprise_plan(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $editor->id],
            ['role' => OrganizationRole::Editor, 'status' => 'active'],
        );

        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Needs approval',
            'status' => 'draft',
            'approval_status' => 'pending',
            'approval_requested_action' => 'schedule',
            'approval_requested_scheduled_at' => now()->addHour(),
        ]));

        Sanctum::actingAs($owner);
        $header = ['X-Organization-Id' => (string) $owner->current_organization_id];

        // Free plan (the default a freshly-provisioned organization gets):
        // rejected, not a 422/500 — the plan is the reason, not a data
        // problem or a crash.
        $this->withHeaders($header)
            ->postJson('/api/v1/posts/'.$post->id.'/approve')
            ->assertStatus(403)
            ->assertJsonPath('message', "Approval workflows are not available on your organization's current plan.");

        $post->refresh();
        $this->assertSame('pending', $post->approval_status, 'a rejected feature-gate check must not have mutated the post');

        // Same organization, same post, only the plan changed: now accepted.
        $this->grantFeatures($owner, QuotaGates::FEATURE_APPROVAL_WORKFLOW);

        $this->withHeaders($header)
            ->postJson('/api/v1/posts/'.$post->id.'/approve')
            ->assertOk();

        $post->refresh();
        $this->assertSame('approved', $post->approval_status);
    }

    public function test_free_plan_member_is_rejected_with_403_reading_the_audit_log_and_accepted_on_an_enterprise_plan(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/organizations/'.$owner->current_organization_id.'/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('message', "The audit log is not available on your organization's current plan.");

        $this->grantFeatures($owner, QuotaGates::FEATURE_AUDIT_LOG);

        $this->getJson('/api/v1/organizations/'.$owner->current_organization_id.'/audit-logs')
            ->assertOk();
    }

    public function test_free_plan_member_is_rejected_with_403_managing_branches_and_accepted_on_an_enterprise_plan(): void
    {
        $admin = User::factory()->create();
        Permission::query()->firstOrCreate(['name' => 'branches.view', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('branches.view');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/branches')
            ->assertStatus(403)
            ->assertJsonPath('message', "Branches are not available on your organization's current plan.");

        $this->grantFeatures($admin, QuotaGates::FEATURE_BRANCHES);

        $this->getJson('/api/v1/branches')->assertOk();
    }

    public function test_free_plan_member_is_rejected_with_403_viewing_the_analytics_dashboard_and_accepted_on_an_enterprise_plan(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/analytics/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('message', "Advanced analytics are not available on your organization's current plan.");

        $this->grantFeatures($owner, QuotaGates::FEATURE_ADVANCED_ANALYTICS);

        $this->getJson('/api/v1/analytics/dashboard')->assertOk();
    }

    /**
     * QuotaGates::unlimitedLimits() (the legacy-grandfathered plan a
     * pre-billing organization's usage backfill assigns) must ALSO enable
     * every feature — a legacy tenant that predates billing must not lose
     * access to something it always had, the same reasoning
     * DefaultPlans::legacyGrandfathered() documents for the numeric gates.
     */
    public function test_legacy_grandfathered_organizations_get_every_feature_too(): void
    {
        foreach (QuotaGates::featureKeys() as $key) {
            $this->assertTrue(
                QuotaGates::unlimitedLimits()[$key],
                "legacy-grandfathered organizations must have {$key} enabled",
            );
        }
    }
}
