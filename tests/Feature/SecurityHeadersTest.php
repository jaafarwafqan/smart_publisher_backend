<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_errors_do_not_expose_a_php_framework_or_exception_header(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertStatus(404)
            ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->assertHeader('Permissions-Policy')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('X-Powered-By')
            ->assertJsonPath('errors.code.0', 'request_failed');
    }

    public function test_hsts_is_sent_only_on_a_secure_request(): void
    {
        $response = $this->getJson('https://localhost/api/v1/does-not-exist');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_secure_deployments_reject_plain_http_before_routes_run(): void
    {
        config()->set('security.require_https', true);

        $response = $this->getJson('/api/v1/auth/login');

        $response->assertStatus(400)
            ->assertSee('HTTPS is required for this environment.');
    }
}
