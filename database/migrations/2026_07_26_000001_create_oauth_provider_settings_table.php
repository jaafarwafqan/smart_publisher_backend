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
        Schema::create('oauth_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('authorize_url')->nullable();
            $table->string('token_url')->nullable();
            $table->json('default_scopes')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_provider_settings');
    }
};
