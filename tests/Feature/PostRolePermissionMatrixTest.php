<?php

namespace Tests\Feature;

use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\User;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sprint 2 acceptance criterion: "every Role × Action combination covered
 * by matrix/data-provider tests." Exercises PostPolicy directly (not via
 * HTTP) against every (role, ownership, action) combination derived from
 * OrganizationRole::permissions() — this is the literal truth table the
 * role matrix in docs/audit/REMEDIATION_TRACKER.md promises, asserted row
 * by row so a future change to the matrix that breaks an intended grant or
 * denial fails loudly here instead of being noticed downstream.
 *
 * Note: PostPolicy::publish() answers "can this user even request a
 * publish/schedule action" — whether it executes immediately or is held
 * for approval is a separate question answered by
 * PostController::canPublishDirectly(), covered in
 * tests/Feature/PostApprovalWorkflowTest.php. An editor requesting to
 * publish their OWN post is correctly `true` here (they can submit the
 * request) even though it won't execute directly.
 */
class PostRolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PostPolicy's last-resort check is hasPermissionTo(...), which
        // throws PermissionDoesNotExist if the row doesn't exist at all —
        // independent of whether the actor holds it. None of these actors
        // hold any Spatie permission in this test (only organization
        // roles), so seeding these just lets that final check evaluate to
        // false instead of throwing.
        foreach (['posts.view', 'posts.update', 'posts.delete', 'posts.publish'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string, 3: bool}>
     */
    public static function matrixProvider(): array
    {
        $rows = [
            // role => [own: [view, update, delete, publish, approve], notOwn: [...]]
            'owner' => [
                'own' => [true, true, true, true, true],
                'notOwn' => [true, true, true, true, true],
            ],
            'admin' => [
                'own' => [true, true, true, true, true],
                'notOwn' => [true, true, true, true, true],
            ],
            'manager' => [
                'own' => [true, true, true, true, true],
                'notOwn' => [true, true, false, true, true],
            ],
            'editor' => [
                'own' => [true, true, true, true, false],
                'notOwn' => [false, false, false, false, false],
            ],
            'viewer' => [
                'own' => [true, false, false, false, false],
                'notOwn' => [true, false, false, false, false],
            ],
        ];

        $actions = ['view', 'update', 'delete', 'publish', 'approve'];
        $cases = [];

        foreach ($rows as $role => $ownership) {
            foreach ($ownership as $ownershipLabel => $expectations) {
                $isOwnPost = $ownershipLabel === 'own';
                foreach ($actions as $index => $action) {
                    $key = "{$role}/{$ownershipLabel}/{$action}";
                    $cases[$key] = [$role, $isOwnPost, $action, $expectations[$index]];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('matrixProvider')]
    public function test_role_action_matrix(string $role, bool $isOwnPost, string $action, bool $expected): void
    {
        $orgOwner = User::factory()->create();
        $actor = User::factory()->create();

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $orgOwner->current_organization_id, 'user_id' => $actor->id],
            ['role' => $role, 'status' => 'active'],
        );

        $post = $this->asOrganizationOf($orgOwner, fn () => Post::query()->create([
            'user_id' => $isOwnPost ? $actor->id : $orgOwner->id,
            'title' => 'Matrix Test Post',
            'status' => 'draft',
        ]));

        $policy = new PostPolicy;
        $result = $policy->{$action}($actor, $post);

        $ownershipLabel = $isOwnPost ? 'own post' : "another user's post";
        $this->assertSame(
            $expected,
            $result,
            "Expected role='{$role}' acting on {$ownershipLabel} to ".($expected ? '' : 'NOT ')."be allowed to '{$action}'.",
        );
    }

    public function test_editor_post_index_never_returns_another_members_posts(): void
    {
        $organizationOwner = User::factory()->create();
        $editor = User::factory()->create();

        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organizationOwner->current_organization_id, 'user_id' => $editor->id],
            ['role' => 'editor', 'status' => 'active'],
        );

        [$ownPost, $otherPost] = $this->asOrganizationOf($organizationOwner, function () use ($organizationOwner, $editor): array {
            $ownPost = Post::query()->create([
                'user_id' => $editor->id,
                'title' => 'Editors own post',
                'status' => 'draft',
            ]);
            $otherPost = Post::query()->create([
                'user_id' => $organizationOwner->id,
                'title' => 'Owners private post',
                'status' => 'draft',
            ]);

            return [$ownPost, $otherPost];
        });

        Sanctum::actingAs($editor);

        $response = $this->withHeaders([
            'X-Organization-Id' => (string) $organizationOwner->current_organization_id,
        ])->getJson('/api/v1/posts');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPost->id);

        $this->assertNotSame($otherPost->id, $response->json('data.0.id'));
    }

    public function test_post_creation_authorizes_the_header_selected_organization_not_a_stale_user_default(): void
    {
        $managerOrganizationOwner = User::factory()->create();
        $viewerOrganizationOwner = User::factory()->create();
        $actor = User::factory()->create([
            'current_organization_id' => $managerOrganizationOwner->current_organization_id,
        ]);

        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $managerOrganizationOwner->current_organization_id,
                'user_id' => $actor->id,
            ],
            ['role' => 'manager', 'status' => 'active'],
        );
        OrganizationMembership::query()->updateOrCreate(
            [
                'organization_id' => $viewerOrganizationOwner->current_organization_id,
                'user_id' => $actor->id,
            ],
            ['role' => 'viewer', 'status' => 'active'],
        );

        Sanctum::actingAs($actor);

        $this->withHeaders([
            'X-Organization-Id' => (string) $viewerOrganizationOwner->current_organization_id,
        ])->postJson('/api/v1/posts', [
            'title' => 'Must not be created in the viewer organization',
        ])->assertForbidden();

        $this->asOrganizationOf($viewerOrganizationOwner, function () use ($actor): void {
            $this->assertSame(0, Post::query()->where('user_id', $actor->id)->count());
        });
    }
}
