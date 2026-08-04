<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTO audit Sprint 5 (SaaS Business) — deliberately just the schema, no
 * pricing. `price_cents`/`billing_interval` are nullable and NOT seeded
 * with any value here: this project has been through repeated findings
 * this whole audit about fabricated-looking data presented as real (fake
 * dashboard stats, aspirational docs describing features that don't
 * exist) — seeding a plausible-looking "Starter $9/mo" row would be the
 * exact same mistake with pricing instead of dashboard numbers. Real
 * plans/prices get created once an actual pricing decision is made
 * (product/business call, not an engineering default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('billing_interval')->nullable(); // 'monthly' | 'yearly', null while unpriced
            $table->string('currency', 3)->nullable();
            // Free-form limits (max_social_accounts, max_scheduled_posts_per_month,
            // max_team_members, ...) — JSON rather than dedicated columns so the
            // limit set can grow without a migration per new limit type. A key
            // absent from this map means "unlimited" for that dimension.
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
