<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed roles and permissions.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'roles.view',
            'roles.assign',
            'permissions.view',
            'branches.view',
            'branches.create',
            'branches.update',
            'branches.delete',
            'tokens.view',
            'tokens.revoke',
            'social-accounts.view',
            'social-accounts.create',
            'social-accounts.update',
            'social-accounts.delete',
            'social-accounts.refresh-token',
            'social-accounts.test-connection',
            'social-accounts.change-status',
            'social-accounts.oauth.authorize',
            'social-accounts.oauth.callback',
            'social-accounts.pages.view',
            'social-accounts.pages.sync',
            'social-accounts.pages.manage',
            'posts.view',
            'posts.create',
            'posts.update',
            'posts.delete',
            'posts.schedule',
            'posts.publish',
            'media.view',
            'media.upload',
            'media.delete',
            'notifications.view',
            'settings.view',
            'system-settings.view',
            'system-settings.manage',
            'publishing.monitor',
            'publishing.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        $adminRole = Role::query()->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'sanctum',
        ]);

        $managerRole = Role::query()->firstOrCreate([
            'name' => 'manager',
            'guard_name' => 'sanctum',
        ]);

        $editorRole = Role::query()->firstOrCreate([
            'name' => 'editor',
            'guard_name' => 'sanctum',
        ]);

        $adminRole->syncPermissions(Permission::query()->where('guard_name', 'sanctum')->get());

        $managerRole->syncPermissions([
            'users.view',
            'users.update',
            'roles.view',
            'permissions.view',
            'branches.view',
            'tokens.view',
            'tokens.revoke',
            'social-accounts.view',
            'social-accounts.create',
            'social-accounts.update',
            'social-accounts.refresh-token',
            'social-accounts.test-connection',
            'social-accounts.change-status',
            'social-accounts.oauth.authorize',
            'social-accounts.oauth.callback',
            'social-accounts.pages.view',
            'social-accounts.pages.sync',
            'social-accounts.pages.manage',
            'posts.view',
            'posts.create',
            'posts.update',
            'posts.schedule',
            'posts.publish',
            'media.view',
            'media.upload',
            'media.delete',
            'notifications.view',
            'settings.view',
            'publishing.monitor',
        ]);

        $editorRole->syncPermissions([
            'users.view',
            'branches.view',
            'social-accounts.view',
            'social-accounts.pages.view',
            'posts.view',
            'posts.create',
            'posts.update',
            'posts.schedule',
            'media.view',
            'media.upload',
            'notifications.view',
            'settings.view',
        ]);

        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@smartpublisher.local'))->first();

        if ($admin) {
            $admin->assignRole('admin');
        }
    }
}
