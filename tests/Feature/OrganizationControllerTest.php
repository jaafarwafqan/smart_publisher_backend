<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_multi_membership_user_sees_every_organization_with_the_correct_role(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();

        OrganizationMembership::query()->create([
            'organization_id' => $otherOwner->current_organization_id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Editor,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations');

        $response->assertOk();
        $orgIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($user->current_organization_id, $orgIds);
        $this->assertContains($otherOwner->current_organization_id, $orgIds);

        $ownRow = collect($response->json('data'))->firstWhere('id', $user->current_organization_id);
        $this->assertSame('owner', $ownRow['role']);
        $this->assertTrue($ownRow['is_current']);

        $otherRow = collect($response->json('data'))->firstWhere('id', $otherOwner->current_organization_id);
        $this->assertSame('editor', $otherRow['role']);
        $this->assertFalse($otherRow['is_current']);
    }

    /**
     * Sprint E (role/permission remediation): GET /organizations is now the
     * single source of truth Flutter consumes directly — no more
     * client-side re-derivation from the role name. Confirms the resolved
     * list matches OrganizationRole::permissions() exactly for two very
     * different roles in the same response.
     */
    public function test_each_membership_includes_its_resolved_permission_list(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();

        OrganizationMembership::query()->create([
            'organization_id' => $otherOwner->current_organization_id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Viewer,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations');
        $response->assertOk();

        $ownRow = collect($response->json('data'))->firstWhere('id', $user->current_organization_id);
        $expectedOwnerPermissions = array_map(
            fn ($permission) => $permission->value,
            OrganizationRole::Owner->permissions(),
        );
        $this->assertEqualsCanonicalizing($expectedOwnerPermissions, $ownRow['permissions']);

        $otherRow = collect($response->json('data'))->firstWhere('id', $otherOwner->current_organization_id);
        $expectedViewerPermissions = array_map(
            fn ($permission) => $permission->value,
            OrganizationRole::Viewer->permissions(),
        );
        $this->assertEqualsCanonicalizing($expectedViewerPermissions, $otherRow['permissions']);
        $this->assertNotContains('social_accounts.connect', $otherRow['permissions']);
    }

    public function test_switching_to_an_organization_the_user_is_not_a_member_of_is_rejected(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/organizations/'.$stranger->current_organization_id.'/switch')
            ->assertForbidden();

        $this->assertNotEquals($stranger->current_organization_id, $user->fresh()->current_organization_id);
    }

    public function test_switching_to_a_real_membership_updates_current_organization(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();

        OrganizationMembership::query()->create([
            'organization_id' => $otherOwner->current_organization_id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Manager,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/organizations/'.$otherOwner->current_organization_id.'/switch');

        $response->assertOk();
        $response->assertJsonPath('data.role', 'manager');
        $this->assertSame($otherOwner->current_organization_id, $user->fresh()->current_organization_id);
    }

    public function test_owner_can_view_and_rename_the_current_organization(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->getJson('/api/v1/organizations/current')
            ->assertOk()
            ->assertJsonPath('data.id', $owner->current_organization_id);

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->putJson('/api/v1/organizations/current', ['name' => 'Renamed Organization'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Organization');
    }

    public function test_editor_can_view_but_cannot_rename_the_current_organization(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        OrganizationMembership::query()->create([
            'organization_id' => $owner->current_organization_id,
            'user_id' => $editor->id,
            'role' => OrganizationRole::Editor,
            'status' => 'active',
        ]);

        Sanctum::actingAs($editor);

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->getJson('/api/v1/organizations/current')
            ->assertOk();

        $this->withHeaders(['X-Organization-Id' => (string) $owner->current_organization_id])
            ->putJson('/api/v1/organizations/current', ['name' => 'Editor Cannot Do This'])
            ->assertForbidden();
    }
}
