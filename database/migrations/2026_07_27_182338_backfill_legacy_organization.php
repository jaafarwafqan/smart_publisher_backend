<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data migration: this product had no tenant concept before Sprint
 * 1, so every existing user/post/social_account/social_page/media/metric is
 * assigned to a single "Legacy Organization" rather than being orphaned.
 * Uses raw DB\Table queries (not Eloquent models) deliberately — this must
 * run identically regardless of what global scopes/traits later get added
 * to the model classes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Legacy Organization',
            'slug' => 'legacy-organization',
            'settings' => json_encode(['migrated_from' => 'pre-tenant-model', 'migrated_at' => now()->toIso8601String()]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleMap = [
            'admin' => 'owner',
            'manager' => 'manager',
            'editor' => 'editor',
        ];

        $users = DB::table('users')->select('id', 'role')->get();
        $memberships = [];
        foreach ($users as $user) {
            $memberships[] = [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'role' => $roleMap[$user->role] ?? 'viewer',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if (! empty($memberships)) {
            DB::table('organization_memberships')->insert($memberships);
        }

        DB::table('users')->update(['current_organization_id' => $organizationId]);

        foreach (['posts', 'social_accounts', 'media_attachments'] as $tableName) {
            DB::table($tableName)->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        }

        // social_pages and post_metrics don't carry their own owning user, so
        // backfill from the parent they already belong to rather than
        // blanket-assigning the legacy org — correct even if a later import
        // ever introduces a second organization before this migration runs.
        DB::table('social_pages')
            ->whereNull('social_pages.organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select social_accounts.organization_id from social_accounts where social_accounts.id = social_pages.social_account_id)'
                ),
            ]);

        DB::table('post_metrics')
            ->whereNull('post_metrics.organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select posts.organization_id from posts where posts.id = post_metrics.post_id)'
                ),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $organizationId = DB::table('organizations')->where('slug', 'legacy-organization')->value('id');

        if ($organizationId === null) {
            return;
        }

        DB::table('organization_memberships')->where('organization_id', $organizationId)->delete();
        DB::table('users')->where('current_organization_id', $organizationId)->update(['current_organization_id' => null]);

        foreach (['posts', 'social_accounts', 'social_pages', 'media_attachments', 'post_metrics'] as $tableName) {
            DB::table($tableName)->where('organization_id', $organizationId)->update(['organization_id' => null]);
        }

        DB::table('organizations')->where('id', $organizationId)->delete();
    }
};
