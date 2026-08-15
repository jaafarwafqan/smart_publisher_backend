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
     * Seed the application's initial admin user without mutating an existing
     * administrator on later, explicitly requested bootstrap runs.
     */
    public function run(): void
    {
        $adminName = (string) (env('ADMIN_NAME') ?: 'Smart Publisher Admin');
        $adminEmail = (string) (env('ADMIN_EMAIL') ?: 'admin@smartpublisher.local');
        $admin = User::query()->where('email', $adminEmail)->first();

        // Bootstrap values apply only while creating the first account. An
        // intentional re-run must never reset an existing administrator's
        // password, profile, branch, role, or activation state.
        if (! $admin instanceof User) {
            $adminPassword = env('ADMIN_PASSWORD');

            if (! $adminPassword) {
                throw new \RuntimeException('ADMIN_PASSWORD must be defined when creating the initial administrator.');
            }

            // The example/known default credential from .env.example must
            // never reach a production admin account.
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

            $admin = User::query()->firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => Hash::make($adminPassword),
                    'role' => 'admin',
                    // This is the explicit platform bootstrap account. It is
                    // intentionally independent of the organization role below.
                    'is_super_admin' => true,
                    'is_active' => true,
                    'branch_id' => $defaultBranch->id,
                    'email_verified_at' => now(),
                ]
            );
        }

        // DatabaseSeeder runs under WithoutModelEvents, which mutes the
        // `created` event User::booted() relies on to auto-provision a
        // personal organization. Provision the organization explicitly for a
        // newly created or previously incomplete bootstrap administrator.
        if (! $admin->memberships()->exists()) {
            PersonalOrganizationProvisioner::provision($admin);
        }
    }
}
