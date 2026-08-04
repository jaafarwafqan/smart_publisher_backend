<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthAdvancedScenariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_works_after_access_token_expiry_when_refresh_token_is_valid(): void
    {
        config()->set('sanctum.expiration', 1);

        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'role' => 'admin',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'session-a',
        ])->assertOk();

        $accessToken = (string) $loginResponse->json('data.access_token');
        $refreshToken = (string) $loginResponse->json('data.refresh_token');

        $accessModel = PersonalAccessToken::findToken($accessToken);
        $accessModel?->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
            'device_name' => 'session-a',
        ])->assertOk()->assertJsonStructure([
            'data' => [
                'access_token',
                'refresh_token',
                'expires_in',
                'token_type',
            ],
        ]);
    }

    public function test_refresh_with_revoked_token_returns_401(): void
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
            'device_name' => 'session-a',
        ])->assertOk();

        $refreshToken = (string) $loginResponse->json('data.refresh_token');

        $refreshModel = PersonalAccessToken::findToken($refreshToken);
        $refreshModel?->delete();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
            'device_name' => 'session-a',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid refresh token.');
    }

    public function test_multiple_sessions_for_same_user_are_independent(): void
    {
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'role' => 'admin',
        ]);

        $sessionA = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'session-a',
        ])->assertOk();

        $sessionB = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'Password@123',
            'device_name' => 'session-b',
        ])->assertOk();

        $accessA = (string) $sessionA->json('data.access_token');
        $accessB = (string) $sessionB->json('data.access_token');

        // Auth::forgetGuards() forces each of the following simulated requests to
        // resolve its user fresh from the bearer token it actually sends, instead
        // of reusing the auth guard's cached user from the previous simulated
        // request within this same test method. A real client switching between
        // two sessions never hits this — each real HTTP request already gets a
        // completely fresh resolution — this only matters when a single test
        // method simulates multiple requests as different identities in a row.
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$accessA)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($accessA));

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$accessA)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$accessB)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
}
