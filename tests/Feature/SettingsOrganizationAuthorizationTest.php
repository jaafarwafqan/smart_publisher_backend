<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettingsOrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_read_organization_settings(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $this->addToOrganization($owner, $admin, OrganizationRole::Admin);

        foreach ([$owner, $admin] as $actor) {
            Sanctum::actingAs($actor);

            $this->withHeaders($this->organizationHeader($owner))
                ->getJson('/api/v1/settings')
                ->assertOk()
                ->assertJsonPath('data.locale', config('app.locale'));
        }
    }

    public function test_manager_with_legacy_global_settings_permission_is_denied(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToOrganization($owner, $manager, OrganizationRole::Manager);

        $legacyPermission = Permission::query()->firstOrCreate([
            'name' => 'settings.view',
            'guard_name' => 'sanctum',
        ]);
        $manager->givePermissionTo($legacyPermission);

        Sanctum::actingAs($manager);

        $this->withHeaders($this->organizationHeader($owner))
            ->getJson('/api/v1/settings')
            ->assertForbidden();
    }

    private function addToOrganization(User $owner, User $member, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $owner->current_organization_id,
                'user_id' => $member->id,
            ],
            ['role' => $role, 'status' => 'active'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function organizationHeader(User $owner): array
    {
        return ['X-Organization-Id' => (string) $owner->current_organization_id];
    }
}
