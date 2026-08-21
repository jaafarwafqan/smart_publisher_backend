<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid-billing model (2026-08-21): a manual super-admin grant and a paid
 * one-time-gateway renewal both extend the same current_period_end column —
 * see BillingPeriodGrantService, the single internal function both paths
 * call. These two columns are how a row created by that grant is told apart
 * from a genuinely paid one after the fact: provider_subscription_id stays
 * null on every manual grant (nothing was ever charged), while
 * granted_by_user_id/granted_reason record who authorized it and why. A
 * free grant with no documented reason is an audit gap — see
 * AdminSubscriptionController, which makes reason a required field on every
 * grant/extend/revert/trial action and writes it to platform_audit_logs too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->foreignId('granted_by_user_id')->nullable()->after('provider_customer_id')->constrained('users')->nullOnDelete();
            $table->text('granted_reason')->nullable()->after('granted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by_user_id');
            $table->dropColumn('granted_reason');
        });
    }
};
