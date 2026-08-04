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
        Schema::create('social_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('page_id');
            $table->string('kind')->default('page');
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('picture_url')->nullable();
            $table->boolean('can_publish')->default(true);
            $table->boolean('is_selected')->default(false);
            $table->string('status')->default('valid');
            $table->string('discovery_source')->default('auto');
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'page_id']);
            $table->index(['social_account_id', 'is_selected']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_pages');
    }
};
