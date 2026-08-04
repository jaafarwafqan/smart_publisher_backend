<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsCalendarOrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_manager_editor_and_viewer_can_read_analytics_without_global_spatie_permissions(): void
    {
        $organizationOwner = User::factory()->create();
        $manager = User::factory()->create();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();

        $this->addToOrganization($organizationOwner, $manager, OrganizationRole::Manager);
        $this->addToOrganization($organizationOwner, $editor, OrganizationRole::Editor);
        $this->addToOrganization($organizationOwner, $viewer, OrganizationRole::Viewer);

        $this->scheduledPost($organizationOwner, $organizationOwner, 'Organization scheduled post');

        foreach ([$manager, $editor, $viewer] as $member) {
            Sanctum::actingAs($member);

            $this->inOrganization($organizationOwner)
                ->getJson('/api/v1/analytics')
                ->assertOk()
                ->assertJsonPath('data.total_posts', 1);
        }
    }

    public function test_calendar_returns_only_an_editors_scheduled_posts_but_manager_and_viewer_see_the_organization_queue(): void
    {
        $organizationOwner = User::factory()->create();
        $manager = User::factory()->create();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();

        $this->addToOrganization($organizationOwner, $manager, OrganizationRole::Manager);
        $this->addToOrganization($organizationOwner, $editor, OrganizationRole::Editor);
        $this->addToOrganization($organizationOwner, $viewer, OrganizationRole::Viewer);

        $ownerPost = $this->scheduledPost($organizationOwner, $organizationOwner, 'Owner scheduled post');
        $managerPost = $this->scheduledPost($organizationOwner, $manager, 'Manager scheduled post');
        $editorPost = $this->scheduledPost($organizationOwner, $editor, 'Editor scheduled post');

        Sanctum::actingAs($manager);
        $managerResponse = $this->inOrganization($organizationOwner)->getJson('/api/v1/calendar');
        $managerResponse->assertOk()->assertJsonCount(3, 'data.items');
        $this->assertEqualsCanonicalizing(
            [$ownerPost->id, $managerPost->id, $editorPost->id],
            collect($managerResponse->json('data.items'))->pluck('post_id')->all(),
        );

        Sanctum::actingAs($editor);
        $editorResponse = $this->inOrganization($organizationOwner)->getJson('/api/v1/calendar');
        $editorResponse
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.post_id', $editorPost->id);

        Sanctum::actingAs($viewer);
        $viewerResponse = $this->inOrganization($organizationOwner)->getJson('/api/v1/calendar');
        $viewerResponse->assertOk()->assertJsonCount(3, 'data.items');
        $this->assertEqualsCanonicalizing(
            [$ownerPost->id, $managerPost->id, $editorPost->id],
            collect($viewerResponse->json('data.items'))->pluck('post_id')->all(),
        );
    }

    public function test_analytics_and_calendar_do_not_expose_rows_from_another_organization(): void
    {
        $organizationOwner = User::factory()->create();
        $manager = User::factory()->create();
        $foreignOrganizationOwner = User::factory()->create();

        $this->addToOrganization($organizationOwner, $manager, OrganizationRole::Manager);

        $organizationPost = $this->scheduledPost($organizationOwner, $organizationOwner, 'Current organization post');
        $foreignPost = $this->scheduledPost($foreignOrganizationOwner, $foreignOrganizationOwner, 'Foreign organization post');

        Sanctum::actingAs($manager);

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/analytics')
            ->assertOk()
            ->assertJsonPath('data.total_posts', 1);

        $calendar = $this->inOrganization($organizationOwner)->getJson('/api/v1/calendar');
        $calendar
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.post_id', $organizationPost->id);

        // Implicit binding is tenant-scoped before the controller grants
        // analytics access, so an ID from another organization is a 404,
        // not an object whose metrics could be inspected.
        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/analytics/posts/'.$foreignPost->id)
            ->assertNotFound();
    }

    public function test_calendar_does_not_reuse_an_organization_wide_cache_entry_after_a_role_downgrade(): void
    {
        $organizationOwner = User::factory()->create();
        $member = User::factory()->create();
        $this->addToOrganization($organizationOwner, $member, OrganizationRole::Manager);

        $ownerPost = $this->scheduledPost($organizationOwner, $organizationOwner, 'Owner scheduled post');
        $memberPost = $this->scheduledPost($organizationOwner, $member, 'Member scheduled post');

        Sanctum::actingAs($member);

        $this->inOrganization($organizationOwner)
            ->getJson('/api/v1/calendar')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        OrganizationMembership::query()
            ->where('organization_id', $organizationOwner->current_organization_id)
            ->where('user_id', $member->id)
            ->update(['role' => OrganizationRole::Editor]);

        $downgradedResponse = $this->inOrganization($organizationOwner)->getJson('/api/v1/calendar');
        $downgradedResponse
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.post_id', $memberPost->id);

        $this->assertNotSame($ownerPost->id, $downgradedResponse->json('data.items.0.post_id'));
    }

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

    private function scheduledPost(User $organizationOwner, User $author, string $title): Post
    {
        return $this->asOrganizationOf($organizationOwner, fn (): Post => Post::query()->create([
            'user_id' => $author->id,
            'title' => $title,
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]));
    }

    /**
     * @return $this
     */
    private function inOrganization(User $organizationOwner): self
    {
        return $this->withHeaders([
            'X-Organization-Id' => (string) $organizationOwner->current_organization_id,
        ]);
    }
}
