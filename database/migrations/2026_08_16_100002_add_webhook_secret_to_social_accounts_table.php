<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (webhook receiver, 2026-08-16): a per-account secret handed to the
 * provider at subscription time and echoed back on every inbound delivery —
 * Telegram's `secret_token` (sent back as the `X-Telegram-Bot-Api-Secret-Token`
 * header on every webhook call) is what proves a request claiming to be
 * "Telegram" for *this* bot actually is. Facebook needs no per-account
 * equivalent (its `X-Hub-Signature-256` is verified with the single shared
 * App Secret already in `social.providers.facebook.client_secret`), so this
 * column stays null for every non-Telegram account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('webhook_secret');
        });
    }
};
