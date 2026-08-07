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
        Schema::table('users', function (Blueprint $table): void {
            // Both are stored encrypted at the model layer (see User's
            // casts()), mirroring how SocialAccount already stores
            // access_token/refresh_token — plaintext TOTP secrets or
            // recovery codes in a database dump would be a live 2FA bypass.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            // Null until the user actually proves they can generate a
            // valid code — enable() alone stores an unconfirmed secret so
            // a client that abandons setup mid-flow never ends up locked
            // out of their own account by a secret they never saved.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
