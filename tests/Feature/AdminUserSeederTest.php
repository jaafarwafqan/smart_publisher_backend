<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DatabaseSeeder runs under WithoutModelEvents, which mutes the
     * `created` event User::booted() relies on to auto-provision a personal
     * organization for a freshly created user. Without AdminUserSeeder
     * explicitly provisioning one itself, the seeded admin ends up with zero
     * organization memberships and every real login request 500s with
     * NoOrganizationMembershipException — reproduced live against both a
     * fresh MySQL and a fresh SQLite database.
     */
    public function test_seeded_admin_has_an_organization_membership_and_can_actually_log_in(): void
    {
        config(['app.env' => 'local']);
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@smartpublisher.local'))->firstOrFail();

        $this->assertNotNull($admin->current_organization_id);
        $this->assertTrue($admin->memberships()->where('status', 'active')->exists());

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => env('ADMIN_EMAIL', 'admin@smartpublisher.local'),
            'password' => env('ADMIN_PASSWORD', 'Admin@123456'),
            'device_name' => 'test',
        ]);

        $response->assertOk()->assertJsonPath('data.access_token', fn ($token) => is_string($token) && $token !== '');
    }

    public function test_re_running_the_seeder_does_not_create_a_duplicate_organization(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@smartpublisher.local'))->firstOrFail();

        $this->assertSame(1, $admin->memberships()->count());
    }
}
