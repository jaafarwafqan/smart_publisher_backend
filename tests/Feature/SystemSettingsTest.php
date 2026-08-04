<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\OAuthProviderSetting;
use App\Models\OAuthProviderSettingAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $user = User::factory()->create();
        foreach (['system-settings.view', 'system-settings.manage'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
        $user->givePermissionTo(['system-settings.view', 'system-settings.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_non_admin_is_forbidden_from_both_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/system-settings/oauth-providers')->assertForbidden();
        $this->putJson('/api/v1/system-settings/oauth-providers/facebook', ['client_id' => 'x'])
            ->assertForbidden();
    }

    public function test_admin_can_view_provider_settings_with_secret_masked(): void
    {
        $this->actingAdmin();

        $response = $this->getJson('/api/v1/system-settings/oauth-providers');

        $response->assertOk();
        $providers = collect($response->json('data'))->pluck('provider');
        $this->assertEqualsCanonicalizing(
            ['facebook', 'instagram', 'linkedin', 'x', 'whatsapp'],
            $providers->all()
        );

        $facebook = collect($response->json('data'))->firstWhere('provider', 'facebook');
        $this->assertArrayNotHasKey('client_secret', $facebook);
        $this->assertFalse($facebook['has_client_secret']);
    }

    public function test_admin_can_set_client_id_and_secret(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'client_id' => 'li-client-id',
            'client_secret' => 'li-client-secret',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.client_id', 'li-client-id')
            ->assertJsonPath('data.has_client_secret', true);

        $this->assertDatabaseHas('oauth_provider_settings', [
            'provider' => 'linkedin',
            'client_id' => 'li-client-id',
        ]);
    }

    public function test_updating_client_id_alone_preserves_the_previously_set_secret(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'client_id' => 'li-client-id',
            'client_secret' => 'li-client-secret',
        ])->assertOk();

        $response = $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'client_id' => 'li-client-id-updated',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.client_id', 'li-client-id-updated')
            ->assertJsonPath('data.has_client_secret', true);

        $setting = OAuthProviderSetting::query()->where('provider', 'linkedin')->first();
        $this->assertSame('li-client-secret', $setting->client_secret);
    }

    public function test_authorize_url_must_be_https_not_a_plain_http_url(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'authorize_url' => 'http://www.linkedin.com/oauth/v2/authorization',
        ])->assertStatus(422)->assertJsonValidationErrors(['authorize_url']);
    }

    public function test_token_url_rejects_a_loopback_address_to_prevent_ssrf(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'token_url' => 'https://127.0.0.1/internal-admin',
        ])->assertStatus(422)->assertJsonValidationErrors(['token_url']);
    }

    public function test_token_url_rejects_the_cloud_metadata_link_local_address(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'token_url' => 'https://169.254.169.254/latest/meta-data/',
        ])->assertStatus(422)->assertJsonValidationErrors(['token_url']);
    }

    public function test_authorize_url_accepts_a_normal_public_https_url(): void
    {
        $this->actingAdmin();

        $response = $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'authorize_url' => 'https://8.8.8.8/oauth/authorize',
        ]);

        $response->assertOk()->assertJsonPath('data.authorize_url', 'https://8.8.8.8/oauth/authorize');
    }

    public function test_invalid_provider_is_rejected(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/tiktok', ['client_id' => 'x'])
            ->assertStatus(422);
    }

    public function test_provider_config_resolution_prefers_the_database_override_over_env(): void
    {
        $this->actingAdmin();

        config()->set('social.providers.facebook.client_id', 'env-client-id');

        $this->putJson('/api/v1/system-settings/oauth-providers/facebook', [
            'client_id' => 'db-client-id',
        ])->assertOk();

        $resolved = app(SocialOAuthManager::class)->providerConfig('facebook');

        $this->assertSame('db-client-id', $resolved['client_id']);
    }

    public function test_connection_succeeds_for_valid_facebook_credentials(): void
    {
        $admin = $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/facebook', [
            'client_id' => 'fb-id',
            'client_secret' => 'fb-secret',
        ])->assertOk();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['access_token' => 'app-token', 'token_type' => 'bearer'], 200),
        ]);

        $response = $this->postJson('/api/v1/system-settings/oauth-providers/facebook/test');

        $response->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertDatabaseHas('oauth_provider_settings', [
            'provider' => 'facebook',
            'last_test_success' => 1,
        ]);
        $this->assertDatabaseHas('oauth_provider_setting_audit_logs', [
            'provider' => 'facebook',
            'user_id' => $admin->id,
            'action' => 'tested',
            'success' => 1,
        ]);
    }

    public function test_connection_fails_for_invalid_facebook_credentials(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/facebook', [
            'client_id' => 'fb-id',
            'client_secret' => 'wrong-secret',
        ])->assertOk();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid client_secret.']], 400),
        ]);

        $response = $this->postJson('/api/v1/system-settings/oauth-providers/facebook/test');

        $response->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.message', 'Invalid client_secret.');

        $this->assertDatabaseHas('oauth_provider_settings', [
            'provider' => 'facebook',
            'last_test_success' => 0,
        ]);
    }

    public function test_connection_reports_not_available_for_a_still_mocked_provider(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'client_id' => 'li-id',
            'client_secret' => 'li-secret',
        ])->assertOk();

        $response = $this->postJson('/api/v1/system-settings/oauth-providers/linkedin/test');

        $response->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.message', 'Live verification is not available for this provider yet.');
    }

    public function test_updating_settings_writes_an_audit_log_entry_with_field_names_only_never_values(): void
    {
        $admin = $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', [
            'client_id' => 'li-client-id',
            'client_secret' => 'super-secret-value',
        ])->assertOk();

        $entry = OAuthProviderSettingAuditLog::query()->where('provider', 'linkedin')->firstOrFail();

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('updated', $entry->action);
        $this->assertEqualsCanonicalizing(['client_id', 'client_secret'], $entry->changed_fields);

        $rawRow = \DB::table('oauth_provider_setting_audit_logs')->where('provider', 'linkedin')->first();
        $this->assertStringNotContainsString('super-secret-value', json_encode($rawRow));
    }

    public function test_audit_log_endpoint_returns_recent_entries_newest_first(): void
    {
        $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', ['client_id' => 'li-1'])->assertOk();
        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', ['client_id' => 'li-2'])->assertOk();

        $response = $this->getJson('/api/v1/system-settings/oauth-providers/linkedin/audit-log');

        $response->assertOk();
        $entries = $response->json('data');
        $this->assertCount(2, $entries);
        $this->assertSame('updated', $entries[0]['action']);
        $this->assertArrayHasKey('user_name', $entries[0]);
    }

    public function test_resolve_state_includes_updated_by_name_and_last_test_status(): void
    {
        $admin = $this->actingAdmin();

        $this->putJson('/api/v1/system-settings/oauth-providers/linkedin', ['client_id' => 'li-1'])->assertOk();

        $response = $this->getJson('/api/v1/system-settings/oauth-providers');

        $linkedin = collect($response->json('data'))->firstWhere('provider', 'linkedin');
        $this->assertSame($admin->name, $linkedin['updated_by_name']);
        $this->assertArrayHasKey('last_tested_at', $linkedin);
        $this->assertArrayHasKey('last_test_success', $linkedin);
    }
}
