<?php

use App\Support\Billing\OrganizationSubscriptionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // The read-only preflight runs first in managed deployment. The
        // idempotent backfill repeats the same rule so a manual migration
        // cannot assign an already-over-Free legacy tenant to zero capacity.
        app(OrganizationSubscriptionBackfill::class)->backfill(now());
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
