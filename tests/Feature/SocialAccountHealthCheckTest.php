<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SocialAccountHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(User $owner): void
    {
        Permission::query()->firstOrCreate(['name' => 'social-accounts.test-connection', 'guard_name' => 'sanctum']);
        $owner->givePermissionTo('social-accounts.test-connection');
        Sanctum::actingAs($owner);
    }

    public function test_a_healthy_facebook_connection_self_heals_an_expired_status(): void
    {
        Http::fake([
            'graph.facebook.com/me*' => Http::response(['id' => 'fb-user-1'], 200),
        ]);

        $user = User::factory()->create();
        $this->actingUser($user);
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_account_id' => 'fb-user-1',
            'access_token' => 'fb-token',
            'status' => 'expired',
            'is_active' => true,
        ]));

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/test');

        $response->assertOk()
            ->assertJsonPath('data.healthy', true);

        $this->assertSame('connected', $account->fresh()->status);
    }

    public function test_an_unhealthy_facebook_connection_marks_status_expired(): void
    {
        Http::fake([
            'graph.facebook.com/me*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
        ]);

        $user = User::factory()->create();
        $this->actingUser($user);
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_account_id' => 'fb-user-2',
            'access_token' => 'bad-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/test');

        $response->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.message', 'Invalid OAuth access token.');

        $this->assertSame('expired', $account->fresh()->status);
    }

    public function test_telegram_delegates_to_the_real_bot_token_check(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 555]], 200),
        ]);

        $user = User::factory()->create();
        $this->actingUser($user);
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_account_id' => '555',
            'access_token' => '123:ABC',
            'status' => 'connected',
            'is_active' => true,
        ]));

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/test');

        $response->assertOk()->assertJsonPath('data.healthy', true);
    }

    public function test_a_still_mocked_provider_honestly_reports_unavailable(): void
    {
        $user = User::factory()->create();
        $this->actingUser($user);
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li-user-1',
            'access_token' => 'li-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/test');

        $response->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.message', 'Live verification is not available for this provider yet.');

        // Honesty matters here: "not available" must not be conflated with
        // "confirmed broken" — a still-mocked provider's status must not be
        // silently downgraded to expired just because we can't check it.
        $this->assertSame('connected', $account->fresh()->status);
    }
}
