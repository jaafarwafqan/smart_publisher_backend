<?php

namespace Tests\Feature;

use App\Models\DataDeletionRequest;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDataDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_record_one_pending_data_deletion_request(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Organization-Id', (string) $user->current_organization_id)
            ->postJson('/api/v1/account/data-deletion-requests', [
                'confirm' => true,
                'reason' => 'Closing the beta account.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $requestId = $response->json('data.id');
        $this->assertNotEmpty($requestId);

        $this->assertDatabaseHas('data_deletion_requests', [
            'id' => (int) $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'status' => 'pending',
            'reason' => 'Closing the beta account.',
        ]);
    }

    public function test_duplicate_pending_submission_returns_the_original_request(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $headers = ['X-Organization-Id' => (string) $user->current_organization_id];
        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/account/data-deletion-requests', ['confirm' => true])
            ->assertStatus(202);
        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/account/data-deletion-requests', ['confirm' => true])
            ->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DataDeletionRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_confirmation_is_required(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->withHeader('X-Organization-Id', (string) $user->current_organization_id)
            ->postJson('/api/v1/account/data-deletion-requests', ['confirm' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm');
    }

    public function test_an_authenticated_user_without_an_active_organization_can_still_request_deletion(): void
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->where('user_id', $user->id)->delete();
        $user->forceFill(['current_organization_id' => null])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/data-deletion-requests', ['confirm' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('data_deletion_requests', [
            'user_id' => $user->id,
            'organization_id' => null,
            'status' => 'pending',
        ]);
    }
}
