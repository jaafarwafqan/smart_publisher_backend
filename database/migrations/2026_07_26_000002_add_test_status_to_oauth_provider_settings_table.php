<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_provider_settings', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_success')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_provider_settings', function (Blueprint $table) {
            $table->dropColumn(['last_tested_at', 'last_test_success']);
        });
    }
};
