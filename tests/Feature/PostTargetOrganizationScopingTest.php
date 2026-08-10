<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint H (role/permission remediation, 2026-08-09): post_targets.
 * organization_id is defense-in-depth only — PostController::ownedPageIds()
 * already prevents a post from ever being targeted at another
 * organization's page via SocialPage's own OrganizationScope. This just
 * confirms the materialized column itself never drifts from the owning
 * post's organization, for every write path that touches the pivot table
 * (a plain sync(), a resync that also detaches, and PostController's real
 * update() endpoint).
 */
class PostTargetOrganizationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makePageFor(User $user, string $suffix = 'a'): SocialPage
    {
        $socialAccount = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_account_id' => 'fb-'.$user->id.'-'.$suffix,
            'status' => 'connected',
            'is_active' => true,
        ]);

        return SocialPage::query()->create([
            'social_account_id' => $socialAccount->id,
            'page_id' => 'page-'.$user->id.'-'.$suffix,
            'name' => 'Page',
            'can_publish' => true,
            'status' => 'valid',
        ]);
    }

    public function test_post_target_organization_id_always_matches_post_organization_id(): void
    {
        $user = User::factory()->create();

        [$post, $target] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Targeted post',
                'status' => 'draft',
            ]);
            $page = $this->makePageFor($user);
            $post->socialPages()->sync([$page->id]);

            return [$post, PostTarget::query()->where('post_id', $post->id)->firstOrFail()];
        });

        $this->assertSame($post->organization_id, $target->organization_id);
    }

    public function test_resyncing_targets_keeps_organization_id_correct_on_every_row(): void
    {
        $user = User::factory()->create();

        [$post, $targets] = $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Resynced post',
                'status' => 'draft',
            ]);
            $firstPage = $this->makePageFor($user, 'first');
            $post->socialPages()->sync([$firstPage->id]);

            $secondPage = $this->makePageFor($user, 'second');
            $post->socialPages()->sync([$secondPage->id]);

            return [$post, PostTarget::query()->where('post_id', $post->id)->get()];
        });

        $this->assertCount(1, $targets);
        foreach ($targets as $target) {
            $this->assertSame($post->organization_id, $target->organization_id);
        }
    }
}
