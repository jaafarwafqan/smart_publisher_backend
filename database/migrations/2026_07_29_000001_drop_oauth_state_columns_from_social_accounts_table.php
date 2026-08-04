<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTO audit 4.4: oauth_state/oauth_redirect_url/oauth_state_expires_at lived
 * as permanent columns on social_accounts, requiring a throwaway "pending"
 * placeholder row (provider_account_id = 'pending_<state>') for the
 * duration of every OAuth authorize->callback round trip. Moved to a Cache
 * entry (see SocialAccountController::beginOAuthAuthorization()/callback())
 * keyed by the state token itself, consumed atomically via Cache::pull() —
 * single-use by construction, expires via the cache TTL instead of a
 * manually-checked timestamp column, and never touches this business table
 * at all for an authorization that's never actually completed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropIndex(['oauth_state']);
            $table->dropColumn(['oauth_state', 'oauth_redirect_url', 'oauth_state_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('oauth_state')->nullable()->after('provider_account_id');
            $table->string('oauth_redirect_url')->nullable()->after('oauth_state');
            $table->timestamp('oauth_state_expires_at')->nullable()->after('oauth_redirect_url');
            $table->index('oauth_state');
        });
    }
};
