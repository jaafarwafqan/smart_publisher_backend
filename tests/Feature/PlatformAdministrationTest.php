<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_cannot_access_any_platform_endpoint(): void
    {
        $user = $this->platformUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_super_admin_can_see_all_organizations_and_users(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->is_active);
        $owner = $this->platformUser();
        $organization = $this->organizationWithOwner($owner);
        $otherUser = $this->platformUser();

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonFragment(['id' => $organization->id]);
        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment(['id' => $otherUser->id]);
        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.organizations_total', Organization::query()->count())
            ->assertJsonPath('data.statistics.users_total', 3);
    }

    public function test_platform_admin_creates_an_organization_with_an_active_owner_and_audit_log(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $owner = $this->platformUser();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'منظمة الاختبار',
            'owner_user_id' => $owner->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'منظمة الاختبار')
            ->assertJsonPath('data.primary_owner.id', $owner->id);

        $organizationId = (int) $response->json('data.id');
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organizationId,
            'user_id' => $owner->id,
            'role' => OrganizationRole::Owner->value,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $superAdmin->id,
            'action' => 'organization.created',
            'auditable_id' => $organizationId,
        ]);
    }

    public function test_platform_admin_created_organization_gets_a_free_plan_subscription(): void
    {
        // This path creates the Organization row directly instead of going
        // through PersonalOrganizationProvisioner, so it must independently
        // guarantee a subscription — otherwise OrganizationEntitlements
        // treats the missing row as unlimited and every quota silently
        // stops being enforced for an org created from the platform panel.
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $owner = $this->platformUser();
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/admin/organizations', [
            'name' => 'مؤسسة بخطة مجانية',
            'owner_user_id' => $owner->id,
        ]);

        $response->assertCreated();
        $organizationId = (int) $response->json('data.id');

        $this->assertDatabaseHas('organization_subscriptions', [
            'organization_id' => $organizationId,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('plans', ['slug' => 'free']);
    }

    public function test_disabled_user_cannot_be_selected_as_organization_owner(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $disabledOwner = $this->platformUser(['is_active' => false]);
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/admin/organizations', [
            'name' => 'مؤسسة مرفوضة',
            'owner_user_id' => $disabledOwner->id,
        ])->assertNotFound();
    }

    public function test_super_admin_without_an_organization_can_log_in(): void
    {
        $superAdmin = $this->platformUser([
            'email' => 'platform-admin@example.test',
            'password' => Hash::make('Password@123456'),
            'is_super_admin' => true,
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $superAdmin->email,
            'password' => 'Password@123456',
        ])->assertOk()
            ->assertJsonPath('data.user.is_super_admin', true);
    }

    public function test_platform_admin_creates_a_user_with_membership_without_auditing_the_password(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $owner = $this->platformUser();
        $organization = $this->organizationWithOwner($owner);
        Sanctum::actingAs($superAdmin);

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'New Editor',
            'email' => 'new-editor@example.test',
            'password' => 'SecurePassword@123',
            'organization_id' => $organization->id,
            'membership_role' => OrganizationRole::Editor->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new-editor@example.test');
        $userId = (int) $response->json('data.id');
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'user_id' => $userId,
            'role' => OrganizationRole::Editor->value,
            'status' => 'active',
        ]);

        $audit = PlatformAuditLog::query()
            ->where('action', 'user.created')
            ->where('auditable_id', $userId)
            ->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->new_values ?? []);
    }

    public function test_membership_sync_cannot_leave_an_organization_without_an_active_owner(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        $owner = $this->platformUser();
        $this->organizationWithOwner($owner);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/v1/admin/users/{$owner->id}/memberships", [
            'memberships' => [],
        ])->assertStatus(422);
    }

    public function test_cannot_revoke_or_disable_the_final_active_super_admin(): void
    {
        $superAdmin = $this->platformUser(['is_super_admin' => true]);
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/v1/admin/users/{$superAdmin->id}/platform-role", [
            'is_super_admin' => false,
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/users/{$superAdmin->id}/status", [
            'is_active' => false,
        ])->assertStatus(422);
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $user = $this->platformUser([
            'email' => 'disabled@example.test',
            'password' => Hash::make('Password@123456'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password@123456',
        ])->assertForbidden();
    }

    private function platformUser(array $attributes = []): User
    {
        return User::withoutPersonalOrganizationProvisioning(
            fn () => User::factory()->create($attributes),
        );
    }

    private function organizationWithOwner(User $owner): Organization
    {
        $organization = Organization::query()->create([
            'name' => 'Organization '.$owner->id,
            'slug' => 'organization-'.$owner->id,
            'status' => 'active',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationRole::Owner,
            'status' => 'active',
        ]);

        return $organization;
    }
}
