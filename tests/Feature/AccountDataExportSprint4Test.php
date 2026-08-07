<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\MediaAttachment;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS): the "download my data" counterpart to the
 * existing account-deletion-request endpoint.
 */
class AccountDataExportSprint4Test extends TestCase
{
    use RefreshDatabase;

    private function addToOrganization(User $organizationOwner, User $member, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $organizationOwner->current_organization_id,
                'user_id' => $member->id,
            ],
            ['role' => $role, 'status' => 'active'],
        );
    }

    public function test_a_user_can_export_their_own_account_data(): void
    {
        $user = User::factory()->create();

        $this->asOrganizationOf($user, function () use ($user): void {
            Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Exported post',
                'content' => 'Body',
                'status' => 'draft',
            ]);

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'export-account',
                'access_token' => 'super-secret-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            MediaAttachment::query()->create([
                'user_id' => $user->id,
                'type' => 'image',
                'disk' => 'local',
                'path' => 'media/photo.jpg',
                'mime_type' => 'image/jpeg',
                'size' => 1024,
            ]);
        });

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/account/data-export')->assertOk();

        $response->assertJsonPath('data.user.email', $user->email);
        $response->assertJsonCount(1, 'data.posts');
        $response->assertJsonPath('data.posts.0.title', 'Exported post');
        $response->assertJsonCount(1, 'data.social_accounts');
        $response->assertJsonPath('data.social_accounts.0.provider', 'facebook');
        $response->assertJsonCount(1, 'data.media_attachments');
        $response->assertJsonPath('data.media_attachments.0.path', 'media/photo.jpg');

        // The whole point of a safe allow-list: the encrypted access_token
        // must never appear anywhere in the export, in any form.
        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
        $this->assertArrayNotHasKey('access_token', $response->json('data.social_accounts.0'));
    }

    public function test_export_spans_every_organization_the_user_belongs_to_not_just_the_active_one(): void
    {
        $user = User::factory()->create();

        $secondOwner = User::factory()->create();
        $this->addToOrganization($secondOwner, $user, OrganizationRole::Editor);

        $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Post in my own org',
            'content' => 'Body',
            'status' => 'draft',
        ]));

        app(TenantContext::class)->run((int) $secondOwner->current_organization_id, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Post in the second org',
            'content' => 'Body',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/account/data-export')->assertOk();

        $response->assertJsonCount(2, 'data.posts');
        $response->assertJsonCount(2, 'data.organizations');
    }

    public function test_the_export_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/account/data-export')->assertStatus(401);
    }
}
