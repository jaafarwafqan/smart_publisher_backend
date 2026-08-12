<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FacebookOAuthProvider::listPages() previously discarded the per-page
// access_token Graph's own /me/accounts response already returns —
// publishing used the account-level user token instead, on the assumption
// (documented, now confirmed wrong by a live publish attempt) that it was
// sufficient. Meta requires the page-scoped token to actually create
// content ON a Page; a user token gets a generic, misleading
// "(#200) publish_actions...deprecated" rejection instead. Encrypted the
// same way SocialAccount.access_token already is (see SocialPage::casts()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_pages', function (Blueprint $table): void {
            $table->text('access_token')->nullable()->after('picture_url');
        });
    }

    public function down(): void
    {
        Schema::table('social_pages', function (Blueprint $table): void {
            $table->dropColumn('access_token');
        });
    }
};
