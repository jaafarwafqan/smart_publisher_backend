<?php

use App\Support\Billing\DefaultPlans;
use App\Support\Billing\QuotaGates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real production incident (2026-08-22): the 2026-08-21 feature-gates
 * review added 4 required keys to QuotaGates::featureKeys(), enforced by
 * Plan::booted() for any NEWLY saved plan — but it never touched plan rows
 * that already existed in a deployed database before that review, since
 * booted() only fires on an actual save(). billing:preflight-free-tier
 * (docker/render/start.sh, runs BEFORE `php artisan migrate`) reads
 * QuotaGates::missingFrom() against every active plan's stored limits and
 * exits non-zero on a genuine mismatch — exactly what staging's own
 * pre-existing "free" plan row now has. Because start.sh uses `set -e`,
 * that non-zero preflight exit silently blocked EVERY subsequent deploy's
 * migrate step from ever running, discovered only by reading the live
 * Render deploy logs directly (two consecutive "update_failed" deploys,
 * both stuck on the OLD container image).
 *
 * Backfills the 4 new keys into every active plan currently missing any of
 * them — `false` for the plan literally named DefaultPlans::FREE_SLUG
 * (matching DefaultPlans::free()'s current intent: free tier, no premium
 * features), `true` for anything else (matching DefaultPlans::
 * legacyGrandfathered()'s established reasoning: a plan that predates a
 * gate must not have something it already had taken away).
 */
return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('plans')->where('is_active', true)->get();

        foreach ($plans as $plan) {
            $limits = json_decode((string) $plan->limits, true) ?? [];
            $missing = QuotaGates::missingFrom($limits);
            if ($missing === []) {
                continue;
            }

            $fallback = $plan->slug === DefaultPlans::FREE_SLUG;
            foreach ($missing as $key) {
                $limits[$key] = ! $fallback;
            }

            DB::table('plans')->where('id', $plan->id)->update([
                'limits' => json_encode($limits),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: this backfills data to match a
        // validation rule that already shipped in an earlier migration
        // (Plan::booted()) — there is no prior state to roll back to that
        // would still pass that same validation.
    }
};
