<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_receive_token_payload(): void
    {
        $user = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'refresh_token',
                    'expires_in',
                    'token_type',
                    'scope',
                    'user',
                    'roles',
                    'permissions',
                ],
                'meta',
                'errors',
            ]);
    }

    public function test_refresh_endpoint_returns_new_token_pair(): void
    {
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'role' => 'admin',
        ]);

        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'phpunit',
        ]);

        $refreshToken = (string) $loginResponse->json('data.refresh_token');

        $refreshResponse = $this->postJson('/api/v1/refresh', [
            'refresh_token' => $refreshToken,
            'device_name' => 'phpunit',
        ]);

        $refreshResponse->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'refresh_token',
                    'expires_in',
                    'token_type',
                    'scope',
                    'user',
                    'roles',
                    'permissions',
                ],
                'meta',
                'errors',
            ]);
    }

    public function test_authenticated_user_can_access_me_and_logout_revokes_access_token(): void
    {
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'role' => 'admin',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'phpunit',
        ])->assertOk();

        $accessToken = (string) $loginResponse->json('data.access_token');

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user',
                    'roles',
                    'permissions',
                    'access_token',
                    'refresh_token',
                    'expires_in',
                    'scope',
                ],
                'meta',
                'errors',
            ]);

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($accessToken));
    }

    public function test_authorized_user_can_revoke_token_by_id(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        OrganizationMembership::query()->create([
            'organization_id' => $actor->current_organization_id,
            'user_id' => $target->id,
            'role' => OrganizationRole::Viewer,
            'status' => 'active',
        ]);

        Permission::query()->firstOrCreate(['name' => 'tokens.revoke', 'guard_name' => 'sanctum']);
        $actor->givePermissionTo('tokens.revoke');

        $issued = $target->createToken('manual-token', ['*']);
        $tokenId = (int) explode('|', $issued->plainTextToken)[0];

        Sanctum::actingAs($actor);

        $this->deleteJson('/api/v1/users/'.$target->id.'/tokens/'.$tokenId)
            ->assertOk()
            ->assertJsonPath('message', 'Token revoked successfully.')
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }
}
