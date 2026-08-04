<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->foreignId('social_page_id')->nullable()->after('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_publication_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('social_page_id');
        });
    }
};
