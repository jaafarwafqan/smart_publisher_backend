<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('discovery_mode')->default('manual')->after('provider');
        });

        DB::table('social_accounts')
            ->whereIn('provider', ['facebook', 'instagram'])
            ->update(['discovery_mode' => 'auto']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('discovery_mode');
        });
    }
};
