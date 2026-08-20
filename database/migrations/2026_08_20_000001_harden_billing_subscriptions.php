<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('stripe_price_id')->nullable()->unique()->after('currency');
        });

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->string('provider_customer_id')->nullable()->after('provider_subscription_id');
        });

        // Backfill before OrganizationEntitlements starts failing closed.
        // The insert is deliberately idempotent so a partially applied deploy
        // can be safely retried.
        $now = now();
        $freePlanId = DB::table('plans')->where('slug', 'free')->value('id');

        if ($freePlanId === null) {
            $freePlanId = DB::table('plans')->insertGetId([
                'name' => 'Free',
                'slug' => 'free',
                'price_cents' => null,
                'billing_interval' => null,
                'currency' => null,
                'limits' => json_encode([
                    'max_team_members' => 5,
                    'max_social_accounts' => 3,
                    'max_scheduled_posts_per_month' => 30,
                ], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('organizations')->orderBy('id')->eachById(function (object $organization) use ($freePlanId, $now): void {
            DB::table('organization_subscriptions')->insertOrIgnore([
                'organization_id' => $organization->id,
                'plan_id' => $freePlanId,
                'status' => 'active',
                'current_period_start' => $now,
                'current_period_end' => null,
                'trial_ends_at' => null,
                'canceled_at' => null,
                'provider_subscription_id' => null,
                'provider_customer_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('provider_customer_id');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropUnique(['stripe_price_id']);
            $table->dropColumn('stripe_price_id');
        });
    }
};
