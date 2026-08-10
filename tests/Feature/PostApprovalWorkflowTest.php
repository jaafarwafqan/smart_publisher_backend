<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sprint 2 product decision: an editor's schedule/publish-now request does
 * not execute directly — it lands pending manager/admin/owner approval.
 * Manager/Admin/Owner's own requests still execute immediately (they
 * already hold posts.publish per the role matrix).
 */
class PostApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // canPublishDirectly()/PostPolicy call hasPermissionTo('posts.publish',
        // 'sanctum'), which throws PermissionDoesNotExist if the permission
        // row itself doesn't exist yet — independent of whether the acting
        // user holds it.
        Permission::query()->firstOrCreate(['name' => 'posts.publish', 'guard_name' => 'sanctum']);
    }

    private function addToSameOrganization(User $owner, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    private function orgHeader(User $organizationOwner): array
    {
        return ['X-Organization-Id' => (string) $organizationOwner->current_organization_id];
    }

    private function makeUsablePage(User $pageOwner): SocialPage
    {
        return $this->asOrganizationOf($pageOwner, function () use ($pageOwner) {
            // 'instagram' routes to GenericOAuthProvider (mock, zero real HTTP
            // calls) — avoids needing Http::fake() just to prove the
            // approval-then-publish plumbing dispatches correctly.
            $account = SocialAccount::query()->create([
                'user_id' => $pageOwner->id,
                'provider' => 'instagram',
                'provider_account_id' => 'ig-'.$pageOwner->id,
                'access_token' => 'mock-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            return SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb_page_'.$pageOwner->id,
                'kind' => 'page',
                'name' => 'A Page',
                'can_publish' => true,
                'is_selected' => true,
                'status' => 'valid',
            ]);
        });
    }

    public function test_editors_schedule_request_is_held_pending_not_executed(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($editor);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'draft');

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('pending', $post->approval_status);
        $this->assertSame('schedule', $post->approval_requested_action);
        $this->assertNotNull($post->approval_requested_scheduled_at);
    }

    public function test_editors_publish_now_request_is_held_pending_and_dispatches_no_jobs(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);
        $page = $this->makeUsablePage($orgOwner);

        $post = $this->asOrganizationOf($orgOwner, function () use ($editor, $page) {
            $post = Post::query()->create([
                'user_id' => $editor->id,
                'title' => 'Editor Draft',
                'status' => 'draft',
            ]);
            $post->socialPages()->sync([$page->id]);

            return $post;
        });

        Sanctum::actingAs($editor);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/publish-now');

        $response->assertStatus(202);

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('pending', $post->approval_status);
        $this->assertSame('publish_now', $post->approval_requested_action);
        $this->assertNotEmpty($post->meta['_pending_publish_page_ids'] ?? null);
    }

    public function test_manager_approving_a_pending_schedule_request_actually_schedules_it(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'status' => 'draft',
            'approval_status' => 'pending',
            'approval_requested_action' => 'schedule',
            'approval_requested_scheduled_at' => now()->addHour(),
        ]));

        Sanctum::actingAs($manager);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/approve');

        $response->assertOk();

        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('approved', $post->approval_status);
        $this->assertSame($manager->id, $post->approved_by);
        $this->assertNotNull($post->approved_at);
    }

    public function test_manager_approving_a_pending_publish_now_request_dispatches_jobs(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);
        $page = $this->makeUsablePage($orgOwner);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'content' => 'Some content.',
            'status' => 'draft',
            'approval_status' => 'pending',
            'approval_requested_action' => 'publish_now',
            'meta' => ['_pending_publish_page_ids' => [$page->id]],
        ]));

        Sanctum::actingAs($manager);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/approve');

        $response->assertOk();

        $post->refresh();
        // The queue is synchronous in tests, so PublishPostJob (mock
        // provider, zero real HTTP) runs to completion immediately —
        // 'published', not just 'scheduled', proves the job actually ran.
        $this->assertSame('published', $post->status);
        $this->assertSame('approved', $post->approval_status);
        $this->assertArrayNotHasKey('_pending_publish_page_ids', $post->meta ?? []);
    }

    public function test_manager_rejecting_a_pending_request_keeps_it_a_draft(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'status' => 'draft',
            'approval_status' => 'pending',
            'approval_requested_action' => 'schedule',
            'approval_requested_scheduled_at' => now()->addHour(),
        ]));

        Sanctum::actingAs($manager);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/reject', ['note' => 'Needs more context.']);

        $response->assertOk();

        $post->refresh();
        $this->assertSame('draft', $post->status);
        $this->assertSame('rejected', $post->approval_status);
        $this->assertSame('Needs more context.', $post->approval_note);
    }

    public function test_editor_cannot_approve_their_own_pending_request(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'status' => 'draft',
            'approval_status' => 'pending',
            'approval_requested_action' => 'schedule',
            'approval_requested_scheduled_at' => now()->addHour(),
        ]));

        Sanctum::actingAs($editor);

        $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/approve')
            ->assertForbidden();
    }

    public function test_approving_a_post_with_no_pending_request_is_rejected_with_422(): void
    {
        $orgOwner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $orgOwner->id,
            'title' => 'Not Pending',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($manager);

        $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/approve')
            ->assertStatus(422);
    }

    public function test_manager_scheduling_their_own_post_executes_directly_without_approval(): void
    {
        $orgOwner = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $manager->id,
            'title' => 'Manager Draft',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($manager);

        $response = $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ]);

        $response->assertOk();

        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertNull($post->approval_status);
    }

    public function test_viewer_cannot_even_request_a_schedule(): void
    {
        $orgOwner = User::factory()->create();
        $viewer = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $viewer, OrganizationRole::Viewer);

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $orgOwner->id,
            'title' => 'Owner Draft',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($viewer);

        $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertForbidden();
    }

    /**
     * Sprint F (role/permission remediation): the Approvals screen this
     * powers has no other way to find the pending queue, or to know who
     * approved a post and when — PostResource previously omitted every
     * approval_* field entirely.
     */
    public function test_manager_can_filter_the_pending_approval_queue_and_see_the_approver_after_deciding(): void
    {
        $orgOwner = User::factory()->create();
        $editor = User::factory()->create();
        $manager = User::factory()->create();
        $this->addToSameOrganization($orgOwner, $editor, OrganizationRole::Editor);
        $this->addToSameOrganization($orgOwner, $manager, OrganizationRole::Manager);

        $pendingPost = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Editor Draft',
            'status' => 'draft',
        ]));
        $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $editor->id,
            'title' => 'Untouched Draft',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($editor);
        $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$pendingPost->id.'/schedule', [
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(202);

        Sanctum::actingAs($manager);

        $queue = $this->withHeaders($this->orgHeader($orgOwner))
            ->getJson('/api/v1/posts?approval_status=pending');
        $queue->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($pendingPost->id, $queue->json('data.0.id'));
        $this->assertSame('pending', $queue->json('data.0.approval_status'));
        $this->assertSame('schedule', $queue->json('data.0.approval_requested_action'));
        $this->assertNull($queue->json('data.0.approved_by'));

        $this->withHeaders($this->orgHeader($orgOwner))
            ->postJson('/api/v1/posts/'.$pendingPost->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.approved_by.id', $manager->id)
            ->assertJsonPath('data.approved_by.name', $manager->name);

        $noLongerPending = $this->withHeaders($this->orgHeader($orgOwner))
            ->getJson('/api/v1/posts?approval_status=pending');
        $noLongerPending->assertOk()->assertJsonCount(0, 'data');
    }
}
