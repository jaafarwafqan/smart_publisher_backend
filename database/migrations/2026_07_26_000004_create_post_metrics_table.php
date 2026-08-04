<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_page_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->boolean('is_available')->default(false);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('reactions')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->json('raw_response')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_page_id']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
