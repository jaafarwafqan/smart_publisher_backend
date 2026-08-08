<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_super_admin')->default(false)->index();
            $table->timestamp('last_login_at')->nullable()->index();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('status')->default('active')->index();
        });

        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'is_super_admin', 'last_login_at']);
        });
    }
};
