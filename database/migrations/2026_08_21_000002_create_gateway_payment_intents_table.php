<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid Iraqi gateways (FIB, ZainCash) — unlike Stripe, neither echoes
 * back rich metadata on its webhook/callback (FIB's is documented as just
 * "payment id + status"; ZainCash's JWT carries the provider's own
 * transaction id). This table is the bridge createCheckout() writes when it
 * starts a payment and verifyCallback()/the webhook processor reads back to
 * recover which organization/plan/months a bare provider reference actually
 * belongs to — see FibBillingGateway/FibWebhookProcessor and their ZainCash
 * counterparts. amount/currency are recorded at checkout time specifically
 * so the webhook processor can re-verify the provider's reported amount
 * against what this application actually charged for, not just against the
 * plan's current price (which could have changed between checkout and
 * payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway'); // 'fib' | 'zaincash'
            $table->string('reference')->index(); // the provider's own payment/transaction id
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedInteger('months');
            $table->unsignedInteger('amount'); // smallest currency unit, matches plans.price_cents' meaning
            $table->string('currency', 3);
            $table->string('status')->default('pending'); // pending | paid | failed | canceled
            $table->timestamps();

            $table->unique(['gateway', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payment_intents');
    }
};
