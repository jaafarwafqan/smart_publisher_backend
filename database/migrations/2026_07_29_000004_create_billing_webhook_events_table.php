<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTO audit Sprint 5 — the idempotency mechanism for whatever payment
 * provider webhook eventually arrives (Stripe/Paddle/etc. all document
 * that a webhook can be delivered more than once for the same event).
 * `provider_event_id` is unique so processing a duplicate delivery is a
 * cheap existence check, not a second full apply of the event — same
 * "atomic claim, not read-then-write" principle as Sprint 3's publishing
 * pipeline. No provider-specific columns/signature-verification logic
 * here yet, since no provider has been chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider'); // 'stripe' | 'paddle' | ... once chosen
            $table->string('provider_event_id');
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
    }
};
