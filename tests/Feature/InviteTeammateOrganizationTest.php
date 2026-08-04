<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regression test for a Sprint 1 gap found while wiring organizations: an
 * admin creating a new user via POST /users was giving that user their own
 * brand-new personal organization (User::booted()'s default) instead of
 * joining the INVITING admin's organization — which defeats the entire
 * point of inviting a teammate. Fixed in UserController::store.
 */
class InviteTeammateOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_created_by_an_admin_joins_the_admins_organization_not_their_own(): void
    {
        Permission::query()->firstOrCreate(['name' => 'users.create', 'guard_name' => 'sanctum']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('users.create');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Teammate',
            'email' => 'teammate@test.local',
            'password' => 'Password@123',
            'organization_role' => 'editor',
        ]);

        $response->assertCreated();

        $newUserId = (int) $response->json('data.id');
        $newUser = User::query()->find($newUserId);

        $this->assertSame($admin->current_organization_id, $newUser->current_organization_id);
        $this->assertTrue($newUser->isMemberOf($admin->current_organization_id));
        $this->assertSame('editor', $newUser->roleIn($admin->current_organization_id)->value);

        // Exactly one membership — not also a stray personal org.
        $this->assertSame(1, $newUser->memberships()->count());
    }
}
