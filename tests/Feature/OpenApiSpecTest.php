<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-08-12 audit finding: GET
 * /api/v1/openapi.json returned 404.
 */
class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_spec_is_served_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/openapi.json')->assertOk();

        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonPath('info.title', 'Smart Publisher API');
        $this->assertIsArray($response->json('paths'));
        $this->assertArrayHasKey('/posts', $response->json('paths'));
    }

    public function test_openapi_spec_is_not_wrapped_in_the_api_envelope(): void
    {
        // The generic ApiEnvelopeMiddleware wraps every api/* JSON response
        // in {success, message, data, errors} unless told not to — this
        // document must stay raw for external tooling (Swagger UI,
        // codegen) that expects {openapi, info, paths, ...} at the top
        // level.
        $response = $this->getJson('/api/v1/openapi.json')->assertOk();

        $response->assertJsonMissingPath('success');
        $response->assertJsonMissingPath('data');
    }
}
