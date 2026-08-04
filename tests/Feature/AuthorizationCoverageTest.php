<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_viewer_is_forbidden_from_endpoints_without_the_required_capability(): void
    {
        $user = User::factory()->create();
        OrganizationMembership::query()
            ->where('organization_id', $user->current_organization_id)
            ->where('user_id', $user->id)
            ->update(['role' => OrganizationRole::Viewer]);

        $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Owned post',
            'status' => 'draft',
        ]));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/users')->assertForbidden();
        // Viewer receives PostsViewAll from the active organization role;
        // post reads deliberately do not depend on a legacy global role.
        $this->getJson('/api/v1/posts')->assertOk();
        $this->postJson('/api/v1/posts', ['title' => 'x'])->assertForbidden();
        // Media inherits the post-view capability in the organization
        // permission matrix, so a viewer can read tenant-scoped media but
        // cannot create, update, or delete it.
        $this->getJson('/api/v1/media')->assertOk();
        // Viewer receives AnalyticsView and PostsViewAll from the active
        // organization role; dashboard reads never depend on global Spatie
        // grants.
        $this->getJson('/api/v1/analytics')->assertOk();
        $this->getJson('/api/v1/calendar')->assertOk();

        // An active member may always read only their own tenant-scoped
        // operational notifications; recipient ownership is enforced by the
        // controller and policy rather than a legacy global permission.
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->getJson('/api/v1/settings')->assertForbidden();

        // Policy allows owner operations for post details.
        $this->getJson('/api/v1/posts/'.$post->id)->assertOk();
    }

    public function test_guest_cannot_access_authenticated_endpoints(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/settings')->assertUnauthorized();
    }
}
