<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 40);
            $table->string('provider', 80);
            $table->string('status', 20);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('input_characters')->default(0);
            $table->unsignedInteger('output_characters')->default(0);
            $table->string('correlation_id')->nullable()->index();
            $table->string('request_id')->nullable()->index();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'operation', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
