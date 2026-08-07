<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\Support\Tenancy\PersonalOrganizationProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's admin user.
     */
    public function run(): void
    {
        $adminName = env('ADMIN_NAME');
        $adminEmail = env('ADMIN_EMAIL');
        $adminPassword = env('ADMIN_PASSWORD');

        if (! $adminPassword) {
            throw new \RuntimeException('ADMIN_PASSWORD must be defined in the environment.');
        }

        // The example/known default credential from .env.example must never
        // reach a production admin account — refuse to seed it there instead
        // of silently creating a well-known, guessable superuser login.
        $knownDefaults = ['Admin@123456', 'CHANGE_ME_BEFORE_DEPLOY', 'password', 'admin', 'admin123'];
        if (app()->environment('production') && in_array($adminPassword, $knownDefaults, true)) {
            throw new \RuntimeException(
                'ADMIN_PASSWORD is set to a known default/placeholder value. Set a real, unique password before seeding in production.'
            );
        }

        $defaultBranch = Branch::query()->firstOrCreate(
            ['code' => 'HQ'],
            ['name' => 'Headquarters', 'is_active' => true]
        );

        $admin = User::query()->updateOrCreate(
            ['email' => $adminEmail ?? 'admin@smartpublisher.local'],
            [
                'name' => $adminName ?? 'Smart Publisher Admin',
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'branch_id' => $defaultBranch->id,
                'email_verified_at' => now(),
            ]
        );

        // DatabaseSeeder runs under WithoutModelEvents, which mutes the
        // `created` event User::booted() relies on to auto-provision a
        // personal organization — without this, a freshly seeded admin has
        // zero organization memberships and every login 500s with
        // NoOrganizationMembershipException. Seeders shouldn't depend on
        // implicit model-event side effects anyway, so this provisions the
        // org explicitly rather than re-enabling model events globally.
        if (! $admin->memberships()->exists()) {
            PersonalOrganizationProvisioner::provision($admin);
        }
    }
}
