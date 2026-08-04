<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTO audit Sprint 5. One row per organization (unique constraint) — an
 * organization with NO row here is treated as unlimited/legacy by
 * OrganizationEntitlements, which is exactly every organization that
 * exists today (this table starts empty; nothing currently in production
 * has ever gone through a checkout flow, because there isn't one yet).
 * That's a deliberate backward-compatibility default, not an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('trialing'); // trialing | active | past_due | canceled
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            // The payment provider's own subscription/customer id, once a
            // provider is chosen (Stripe/Paddle/etc.) — nullable because
            // that choice hasn't been made yet.
            $table->string('provider_subscription_id')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_subscriptions');
    }
};
