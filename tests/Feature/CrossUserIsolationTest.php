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
 * Two isolation layers now exist and are tested separately:
 *
 * 1. Cross-ORGANIZATION isolation (Sprint 1, OrganizationScope): every
 *    User::factory()->create() gets its own personal organization (see
 *    User::booted()), so two independently-created users are, by default,
 *    in different organizations. A request for another organization's post
 *    should 404 — route-model-binding's implicit query is scoped by
 *    OrganizationScope, so it can't even find the row to authorize against.
 *    This is what actually closes P0-1 (see docs/audit/REMEDIATION_TRACKER.md).
 *
 * 2. Cross-USER, SAME-organization isolation (Sprint 0 containment, still
 *    real and still worth guarding): a user with no special
 *    role/permission, invited into the SAME organization as the resource
 *    owner, still can't view/edit/publish someone else's post there — this
 *    is PostPolicy's owner-or-permission check. To actually exercise this
 *    layer the acting user must select the shared org as active via the
 *    X-Organization-Id header (their own current_organization_id still
 *    points at their own personal org otherwise, and the request would 404
 *    from layer 1 instead of 403 from layer 2 — asserting the wrong thing).
 */
class CrossUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['posts.view', 'posts.update', 'posts.delete', 'posts.publish', 'posts.create'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
    }

    /** Adds $user to $owner's organization with $role, without giving them their own separate org. */
    private function addToSameOrganization(User $owner, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $owner->current_organization_id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );
    }

    /** The header a multi-membership user sends to make $organizationOwner's org active for this request. */
    private function orgHeader(User $organizationOwner): array
    {
        return ['X-Organization-Id' => (string) $organizationOwner->current_organization_id];
    }

    // --- Layer 1: cross-organization (different orgs entirely) ---

    public function test_user_in_a_different_organization_gets_404_not_403_for_anothers_post(): void
    {
        $owner = User::factory()->create();
        $strangerOrg = User::factory()->create();
        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Only Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($strangerOrg);

        // Not 403 — the post isn't even visible to query against from a
        // different organization, so implicit route-model-binding 404s
        // before the policy ever runs. This is the real P0-1 fix: guessing
        // a valid ID from another tenant doesn't leak its existence.
        $this->getJson('/api/v1/posts/'.$post->id)->assertNotFound();
    }

    // --- Layer 2: same organization, different user, no special role ---

    /**
     * NOT an isolation gap: OrganizationRole::Viewer::canViewContent() is
     * deliberately true for every role (a viewer's whole purpose is
     * read-only visibility into the organization's content — consistent
     * with how Manager/Editor/Admin/Owner already have broad, org-wide
     * view access in this codebase's pre-existing owner-or-permission
     * design, not per-post ACLs). This asserts that intended behavior
     * explicitly, rather than leaving it as an untested assumption.
     */
    public function test_viewer_role_can_view_another_users_post_in_the_same_organization(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $this->addToSameOrganization($owner, $viewer, OrganizationRole::Viewer);
        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Only Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($viewer);

        $this->withHeaders($this->orgHeader($owner))
            ->getJson('/api/v1/posts/'.$post->id)
            ->assertOk()
            ->assertJsonPath('data.id', $post->id);
    }

    public function test_bare_user_cannot_update_another_users_post_in_the_same_organization(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->addToSameOrganization($owner, $intruder, OrganizationRole::Viewer);
        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Only Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($intruder);

        $this->withHeaders($this->orgHeader($owner))
            ->putJson('/api/v1/posts/'.$post->id, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Owner Only Post']);
    }

    public function test_bare_user_cannot_delete_another_users_post_in_the_same_organization(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->addToSameOrganization($owner, $intruder, OrganizationRole::Viewer);
        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Only Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($intruder);

        $this->withHeaders($this->orgHeader($owner))
            ->deleteJson('/api/v1/posts/'.$post->id)
            ->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_bare_user_cannot_publish_another_users_post_in_the_same_organization(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->addToSameOrganization($owner, $intruder, OrganizationRole::Viewer);
        $post = $this->asOrganizationOf($owner, fn () => Post::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Only Post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($intruder);

        $this->withHeaders($this->orgHeader($owner))
            ->postJson('/api/v1/posts/'.$post->id.'/publish-now')
            ->assertForbidden();
    }

    public function test_user_cannot_attach_another_users_social_page_even_in_the_same_organization(): void
    {
        $pageOwner = User::factory()->create();
        $attacker = User::factory()->create();
        $this->addToSameOrganization($pageOwner, $attacker, OrganizationRole::Editor);
        $attacker->givePermissionTo('posts.create');

        $page = $this->asOrganizationOf($pageOwner, function () use ($pageOwner) {
            $account = SocialAccount::query()->create([
                'user_id' => $pageOwner->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-'.$pageOwner->id,
                'status' => 'connected',
                'is_active' => true,
            ]);

            return SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb_page_123',
                'kind' => 'page',
                'name' => "Owner's Page",
                'can_publish' => true,
                'is_selected' => true,
                'status' => 'valid',
            ]);
        });

        Sanctum::actingAs($attacker);

        $response = $this->withHeaders($this->orgHeader($pageOwner))
            ->postJson('/api/v1/posts', [
                'title' => 'Post with someone else\'s page',
                'target_page_ids' => [$page->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_page_ids']);
        $this->assertDatabaseMissing('post_targets', [
            'social_page_id' => $page->id,
        ]);
    }

    public function test_user_cannot_publish_through_another_users_social_page_even_in_the_same_organization(): void
    {
        $pageOwner = User::factory()->create();
        $attacker = User::factory()->create();
        $this->addToSameOrganization($pageOwner, $attacker, OrganizationRole::Editor);
        $attacker->givePermissionTo('posts.publish');

        [$page, $post] = $this->asOrganizationOf($pageOwner, function () use ($pageOwner, $attacker) {
            $account = SocialAccount::query()->create([
                'user_id' => $pageOwner->id,
                'provider' => 'facebook',
                'provider_account_id' => 'fb-'.$pageOwner->id,
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'fb_page_456',
                'kind' => 'page',
                'name' => "Owner's Page",
                'can_publish' => true,
                'is_selected' => true,
                'status' => 'valid',
            ]);

            $post = Post::query()->create([
                'user_id' => $attacker->id,
                'title' => 'Attacker Post',
                'status' => 'draft',
            ]);

            return [$page, $post];
        });

        Sanctum::actingAs($attacker);

        $response = $this->withHeaders($this->orgHeader($pageOwner))
            ->postJson('/api/v1/posts/'.$post->id.'/publish-now', [
                'social_page_ids' => [$page->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['social_page_ids']);
    }
}
