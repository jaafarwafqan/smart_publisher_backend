<?php

namespace Tests\Feature;

use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real production incident (2026-08-22): billing:preflight-free-tier used
 * to just REPORT a plan missing the 4 feature-gate keys the 2026-08-21
 * review added, and exit non-zero. docker/render/start.sh runs this
 * command BEFORE `php artisan migrate`, under `set -e` — so that non-zero
 * exit silently blocked migrate from ever running at all, on every
 * subsequent deploy, discovered only by reading live Render deploy logs. A
 * same-day data-backfill migration meant to fix exactly this could never
 * run either, since it is itself gated behind the same blocked migrate
 * step — a genuine chicken-and-egg deadlock. The command must fix this
 * data itself, since nothing downstream of it can.
 */
class BillingPreflightSelfHealTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_free_plan_is_self_healed_and_the_command_still_succeeds(): void
    {
        $this->seedStalePlan(DefaultPlans::FREE_SLUG, ['max_team_members' => 3, 'max_social_accounts' => 2, 'max_scheduled_posts_per_month' => 30]);

        $this->artisan('billing:preflight-free-tier')
            ->expectsOutputToContain("Self-healed plan 'free'")
            ->assertExitCode(0);

        $limits = json_decode((string) DB::table('plans')->where('slug', DefaultPlans::FREE_SLUG)->value('limits'), true);
        foreach (QuotaGates::featureKeys() as $key) {
            $this->assertFalse($limits[$key]);
        }
    }

    public function test_a_stale_non_free_plan_is_self_healed_with_true(): void
    {
        $this->seedStalePlan('legacy-enterprise', ['max_team_members' => 100, 'max_social_accounts' => 100, 'max_scheduled_posts_per_month' => 5000]);

        $this->artisan('billing:preflight-free-tier')->assertExitCode(0);

        $limits = json_decode((string) DB::table('plans')->where('slug', 'legacy-enterprise')->value('limits'), true);
        foreach (QuotaGates::featureKeys() as $key) {
            $this->assertTrue($limits[$key]);
        }
    }

    /** @param array<string, mixed> $limits */
    private function seedStalePlan(string $slug, array $limits): void
    {
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
}
