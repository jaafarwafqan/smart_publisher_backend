<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_endpoints_expose_message_and_data_contract_shape(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $createPost = $this->postJson('/api/v1/posts', [
            'title' => 'Contract post',
            'content' => 'Body',
        ])->assertCreated();

        $createPost->assertJsonStructure([
            'message',
            'data',
        ]);

        $this->getJson('/api/v1/media')->assertOk()->assertJsonStructure([
            'data',
            'meta',
        ]);

        // ApiEnvelopeMiddleware wraps every /api/* response the same way — there is no
        // endpoint that returns an unwrapped payload, so this checks the metrics under
        // "data" like every other endpoint.
        $this->getJson('/api/v1/analytics')->assertOk()->assertJsonStructure([
            'data' => ['total_posts', 'published', 'failed', 'scheduled', 'draft', 'engagement', 'updated_at'],
        ]);
    }
}
