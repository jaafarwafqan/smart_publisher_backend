<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reproduced live against a real Facebook Page sync: Meta's picture CDN
// URLs (signed, long query strings) routinely exceed VARCHAR(255) —
// SQLSTATE[22001] "Data too long for column 'picture_url'" aborted every
// sync attempt for a real page. Every other *_url column in this codebase
// (oauth_redirect_url, authorize_url, token_url) holds a short, our-own or
// well-known provider endpoint — this is the only one holding unpredictable
// third-party CDN URLs, so it's the only one that needed widening.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_pages', function (Blueprint $table): void {
            $table->text('picture_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_pages', function (Blueprint $table): void {
            $table->string('picture_url')->nullable()->change();
        });
    }
};
