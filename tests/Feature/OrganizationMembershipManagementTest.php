<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationMembershipManagementTest extends TestCase
{
    use RefreshDatabase;

    private function addToSameOrganization(User $owner, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    private function orgHeader(User $organizationOwner): array
    {
        return ['X-Organization-Id' => (string) $organizationOwner->current_organization_id];
    }

    public function test_owner_can_invite_an_existing_user_with_a_role(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();

        Sanctum::actingAs($owner);

        $response = $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.role', 'editor');
        $this->assertTrue($invitee->isMemberOf($owner->current_organization_id));
        $this->assertSame('editor', $invitee->roleIn($owner->current_organization_id)->value);
    }

    public function test_admin_can_invite_a_member_too(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $invitee = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'manager',
            ])
            ->assertCreated();
    }

    public function test_manager_cannot_invite_members(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $invitee = User::factory()->create();
        $this->addToSameOrganization($owner, $manager, OrganizationRole::Manager);

        Sanctum::actingAs($manager);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'editor',
            ])
            ->assertForbidden();
    }

    public function test_admin_cannot_grant_the_owner_role(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $invitee = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'owner',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_grant_the_owner_role(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();

        Sanctum::actingAs($owner);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $invitee->email,
                'role' => 'owner',
            ])
            ->assertCreated();
    }

    public function test_admin_cannot_promote_an_editor_to_owner_via_role_change(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);
        $this->addToSameOrganization($owner, $editor, OrganizationRole::Editor);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->putJson('/api/v1/organization/members/'.$editor->id, ['role' => 'owner'])
            ->assertForbidden();
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->putJson('/api/v1/organization/members/'.$admin->id, ['role' => 'owner'])
            ->assertForbidden();
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        $owner = User::factory()->create();

        Sanctum::actingAs($owner);

        $this->withHeaders($this->orgHeader($owner))
            ->deleteJson('/api/v1/organization/members/'.$owner->id)
            ->assertForbidden();
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);

        // admin has members.remove but the target here is the sole owner.
        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->deleteJson('/api/v1/organization/members/'.$owner->id)
            ->assertStatus(422);

        $this->assertTrue($owner->isMemberOf($owner->current_organization_id));
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $this->addToSameOrganization($owner, $admin, OrganizationRole::Admin);

        Sanctum::actingAs($admin);

        $this->withHeaders($this->orgHeader($owner))
            ->putJson('/api/v1/organization/members/'.$owner->id, ['role' => 'admin'])
            ->assertStatus(422);

        $this->assertSame('owner', $owner->roleIn($owner->current_organization_id)->value);
    }

    public function test_a_second_owner_can_be_removed_leaving_the_first_intact(): void
    {
        $owner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $this->addToSameOrganization($owner, $secondOwner, OrganizationRole::Owner);

        Sanctum::actingAs($owner);

        $this->withHeaders($this->orgHeader($owner))
            ->deleteJson('/api/v1/organization/members/'.$secondOwner->id)
            ->assertOk();

        $this->assertFalse($secondOwner->fresh()->isMemberOf($owner->current_organization_id));
        $this->assertTrue($owner->isMemberOf($owner->current_organization_id));
    }

    public function test_inviting_an_existing_member_again_is_rejected(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($owner, $editor, OrganizationRole::Editor);

        Sanctum::actingAs($owner);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/organization/members', [
                'email' => $editor->email,
                'role' => 'manager',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_owner_can_list_members_with_roles(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($owner, $editor, OrganizationRole::Editor);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders($this->orgHeader($owner))
            ->getJson('/api/v1/organization/members');

        $response->assertOk();
        $roles = collect($response->json('data'))->pluck('role', 'user_id');
        $this->assertSame('owner', $roles[$owner->id]);
        $this->assertSame('editor', $roles[$editor->id]);
    }
}
