<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (webhook receiver, 2026-08-16): the inbound counterpart to
 * `billing_webhook_events` (same idempotency shape — a unique
 * (provider, provider_event_id) pair makes a duplicate delivery a cheap
 * existence check, not a second full apply of the event), but for real
 * platform delivery/engagement callbacks (Facebook Page, Telegram Bot)
 * instead of payments. No BelongsToOrganization here, deliberately: a
 * webhook arrives with no authenticated tenant context at all — the
 * organization (when it can be resolved from the matched social account/
 * page) is stored as a plain nullable column for audit/lookup, not a
 * tenant-scoping column enforced by OrganizationScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider'); // 'facebook' | 'telegram'
            $table->string('provider_event_id');
            $table->string('type');
            $table->json('payload');
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_webhook_events');
    }
};
