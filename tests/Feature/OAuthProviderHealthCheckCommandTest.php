<?php

namespace Tests\Feature;

use App\Models\OAuthProviderSetting;
use App\Models\OAuthProviderSettingAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthProviderHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tests_only_configured_providers_and_persists_the_result(): void
    {
        OAuthProviderSetting::query()->create([
            'provider' => 'facebook',
            'client_id' => 'fb-id',
            'client_secret' => 'fb-secret',
        ]);
        OAuthProviderSetting::query()->create([
            'provider' => 'linkedin',
            'client_id' => 'li-id',
            'client_secret' => 'li-secret',
        ]);
        // instagram/x/whatsapp left unconfigured — should be skipped entirely.

        Http::fake([
            'graph.facebook.com/*' => Http::response(['access_token' => 'app-token'], 200),
        ]);

        $this->artisan('oauth-providers:health-check')->assertExitCode(0);

        $this->assertDatabaseHas('oauth_provider_settings', [
            'provider' => 'facebook',
            'last_test_success' => 1,
        ]);
        $this->assertDatabaseHas('oauth_provider_settings', [
            'provider' => 'linkedin',
            'last_test_success' => 0,
        ]);

        $this->assertSame(
            2,
            OAuthProviderSettingAuditLog::query()->where('action', 'tested')->count()
        );
        $this->assertDatabaseMissing('oauth_provider_setting_audit_logs', ['provider' => 'instagram']);

        $facebookEntry = OAuthProviderSettingAuditLog::query()->where('provider', 'facebook')->firstOrFail();
        $this->assertNull($facebookEntry->user_id);
    }

    public function test_it_skips_every_provider_when_none_are_configured(): void
    {
        $this->artisan('oauth-providers:health-check')->assertExitCode(0);

        $this->assertSame(0, OAuthProviderSettingAuditLog::query()->count());
    }
}
