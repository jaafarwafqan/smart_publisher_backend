<?php

namespace Tests\Feature;

use App\Models\OrganizationMembership;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\Billing\FreeTierGrandfathering;
use App\Support\Billing\OrganizationSubscriptionBackfill;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeTierGrandfatheringTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubscribed_organizations_already_over_free_are_marked_for_grandfathering(): void
    {
        $owner = User::factory()->create();
        $organizationId = (int) $owner->current_organization_id;

        OrganizationSubscription::query()->where('organization_id', $organizationId)->delete();

        // Owner + five existing active members: this organization has six
        // members before billing is introduced, above Free's explicit five.
        for ($member = 0; $member < 5; $member++) {
            $user = User::withoutPersonalOrganizationProvisioning(
                fn (): User => User::factory()->create(),
            );

            OrganizationMembership::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'role' => 'editor',
                'status' => 'active',
            ]);
        }

        $audit = app(FreeTierGrandfathering::class)->auditOrganizationsWithoutSubscriptions();
        $organization = collect($audit)->firstWhere('id', $organizationId);

        $this->assertNotNull($organization);
        $this->assertSame(6, $organization['usage'][QuotaGates::TEAM_MEMBERS]);
        $this->assertTrue($organization['exceeds_free_limits']);
    }

    public function test_usage_exactly_at_a_free_limit_is_not_grandfathered(): void
    {
        $grandfathering = app(FreeTierGrandfathering::class);

        $this->assertFalse($grandfathering->exceedsLimits([
            QuotaGates::TEAM_MEMBERS => 5,
            QuotaGates::SOCIAL_ACCOUNTS => 3,
            QuotaGates::SCHEDULED_POSTS_PER_MONTH => 30,
        ], QuotaGates::fallbackLimits()));
    }

    public function test_backfill_assigns_the_explicit_legacy_plan_to_an_over_free_organization(): void
    {
        $owner = User::factory()->create();
        $organizationId = (int) $owner->current_organization_id;

        OrganizationSubscription::query()->where('organization_id', $organizationId)->delete();

        for ($member = 0; $member < 5; $member++) {
            $user = User::withoutPersonalOrganizationProvisioning(
                fn (): User => User::factory()->create(),
            );

            OrganizationMembership::query()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'role' => 'editor',
                'status' => 'active',
            ]);
        }

        app(OrganizationSubscriptionBackfill::class)->backfill(now());

        $subscription = OrganizationSubscription::query()
            ->with('plan')
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $this->assertSame('legacy-grandfathered', $subscription->plan?->slug);
        $this->assertSame(QuotaGates::unlimitedLimits(), $subscription->plan?->limits);
    }
}
