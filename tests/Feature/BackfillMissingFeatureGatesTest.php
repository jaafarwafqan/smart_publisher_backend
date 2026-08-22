<?php

namespace Tests\Feature;

use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real production incident (2026-08-22): a plan row that predates the
 * 2026-08-21 feature-gates review has no way to gain the new required keys
 * on its own — Plan::booted() only validates on an actual save(), and
 * nothing re-saves an existing row just because a new key was added
 * elsewhere. billing:preflight-free-tier found exactly this on staging and
 * (via docker/render/start.sh's `set -e`) silently blocked every
 * subsequent deploy's migrate step from running at all.
 */
class BackfillMissingFeatureGatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_free_plan_missing_the_new_keys_is_backfilled_with_false(): void
    {
        $this->seedStalePlan(DefaultPlans::FREE_SLUG, ['max_team_members' => 3, 'max_social_accounts' => 2, 'max_scheduled_posts_per_month' => 30]);

        $this->runBackfillMigration();

        $limits = DB::table('plans')->where('slug', DefaultPlans::FREE_SLUG)->value('limits');
        $limits = json_decode((string) $limits, true);

        foreach (QuotaGates::featureKeys() as $key) {
            $this->assertFalse($limits[$key], "expected {$key} to backfill to false on the free plan");
        }
    }

    public function test_a_stale_paid_plan_missing_the_new_keys_is_backfilled_with_true(): void
    {
        $this->seedStalePlan('legacy-enterprise', ['max_team_members' => 100, 'max_social_accounts' => 100, 'max_scheduled_posts_per_month' => 5000]);

        $this->runBackfillMigration();

        $limits = DB::table('plans')->where('slug', 'legacy-enterprise')->value('limits');
        $limits = json_decode((string) $limits, true);

        foreach (QuotaGates::featureKeys() as $key) {
            $this->assertTrue($limits[$key], "expected {$key} to backfill to true on a non-free legacy plan");
        }
    }

    public function test_a_plan_already_declaring_every_key_is_left_untouched(): void
    {
        $this->seedStalePlan('already-complete', array_replace(
            ['max_team_members' => 10, 'max_social_accounts' => 10, 'max_scheduled_posts_per_month' => 500],
            array_fill_keys(QuotaGates::featureKeys(), true),
        ));

        $before = DB::table('plans')->where('slug', 'already-complete')->value('updated_at');
        sleep(1);
        $this->runBackfillMigration();
        $after = DB::table('plans')->where('slug', 'already-complete')->value('updated_at');

        $this->assertSame($before, $after);
    }

    /** @param array<string, mixed> $limits */
    private function seedStalePlan(string $slug, array $limits): void
    {
        // A base TestCase/provisioning path elsewhere in the suite may
        // already have created a real 'free' plan row for this slug —
        // replace it outright so this test controls its exact stale shape.
        DB::table('plans')->where('slug', $slug)->delete();

        // Deliberately DB::table(), not Plan::query()->create() — bypasses
        // Plan::booted()'s validation entirely, exactly matching how a real
        // row saved before that validation existed would still look today.
        DB::table('plans')->insert([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'limits' => json_encode($limits),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runBackfillMigration(): void
    {
        (require base_path('database/migrations/2026_08_22_000001_backfill_missing_feature_gates_on_existing_plans.php'))->up();
    }
}
