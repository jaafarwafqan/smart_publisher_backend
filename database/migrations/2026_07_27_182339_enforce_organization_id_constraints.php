<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locks organization_id to NOT NULL now that the backfill migration has run
 * (every existing row has one), and adds the composite indexes the CTO
 * audit's Sprint 1 checklist names explicitly. Requires doctrine/dbal
 * (installed alongside this migration) since Schema::table(...)->change()
 * needs it to alter an existing column on both SQLite and MySQL.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'status']);
        });

        Schema::table('social_pages', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'status']);
        });

        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('post_metrics', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['organization_id', 'created_at']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('social_pages', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('post_metrics', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'provider']);
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
    }
};
