<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        // dashboard() (full analytics) is now gated behind
        // feature_advanced_analytics — see the 2026-08 feature-gates
        // review. Granted unconditionally here since every test in this
        // file shares this one helper and granting an unused feature flag
        // is harmless for the ones that don't touch /analytics/dashboard.
        $this->grantFeatures($user, QuotaGates::FEATURE_ADVANCED_ANALYTICS);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Fixture posts/pages must land in $organizationUser's own organization
     * (their default active org for the request, per TenantContextResolver)
     * even though the post is nominally owned by a fresh unrelated user —
     * otherwise OrganizationScope would hide them from the acting user's
     * analytics queries entirely.
     */
    private function makePostWithPage(User $organizationUser, string $provider = 'facebook'): array
    {
        return $this->asOrganizationOf($organizationUser, function () use ($provider) {
            $user = User::factory()->create();
            $post = Post::query()->create(['user_id' => $user->id, 'title' => 'Post', 'status' => 'published']);
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_account_id' => 'acc-'.$post->id,
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-'.$post->id,
                'name' => 'Page',
                'status' => 'valid',
            ]);

            return [$post, $page];
        });
    }

    public function test_show_returns_a_zeroed_unavailable_summary_for_a_post_with_no_metrics(): void
    {
        $user = $this->actingUser();
        [$post] = $this->makePostWithPage($user);

        $response = $this->getJson('/api/v1/analytics/posts/'.$post->id);

        $response->assertOk()
            ->assertJsonPath('data.post_id', (string) $post->id)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.impressions', 0);
    }

    public function test_show_aggregates_metrics_across_multiple_pages_for_one_post(): void
    {
        $user = $this->actingUser();
        [$post, $pageA] = $this->makePostWithPage($user);
        [, $pageB] = $this->makePostWithPage($user);

        $this->asOrganizationOf($user, function () use ($post, $pageA, $pageB): void {
            PostMetric::query()->create([
                'post_id' => $post->id, 'social_page_id' => $pageA->id, 'provider' => 'facebook',
                'is_available' => true, 'impressions' => 100, 'reach' => 80, 'clicks' => 5, 'reactions' => 3, 'shares' => 1, 'comments' => 2,
            ]);
            PostMetric::query()->create([
                'post_id' => $post->id, 'social_page_id' => $pageB->id, 'provider' => 'facebook',
                'is_available' => true, 'impressions' => 50, 'reach' => 40, 'clicks' => 2, 'reactions' => 1, 'shares' => 0, 'comments' => 1,
            ]);
        });

        $response = $this->getJson('/api/v1/analytics/posts/'.$post->id);

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.impressions', 150)
            ->assertJsonPath('data.reach', 120)
            ->assertJsonPath('data.clicks', 7)
            ->assertJsonPath('data.reactions', 4)
            ->assertJsonPath('data.shares', 1)
            ->assertJsonPath('data.comments', 3);
    }

    public function test_bulk_returns_a_row_for_every_requested_post_including_ones_with_no_metrics(): void
    {
        $user = $this->actingUser();
        [$postA, $pageA] = $this->makePostWithPage($user);
        [$postB] = $this->makePostWithPage($user);

        $this->asOrganizationOf($user, function () use ($postA, $pageA): void {
            PostMetric::query()->create([
                'post_id' => $postA->id, 'social_page_id' => $pageA->id, 'provider' => 'facebook',
                'is_available' => true, 'impressions' => 200, 'reach' => 150, 'clicks' => 10, 'reactions' => 5, 'shares' => 2, 'comments' => 1,
            ]);
        });

        $response = $this->getJson('/api/v1/analytics/posts?post_ids[]='.$postA->id.'&post_ids[]='.$postB->id);

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertCount(2, $data);
        $this->assertTrue($data->firstWhere('post_id', (string) $postA->id)['available']);
        $this->assertFalse($data->firstWhere('post_id', (string) $postB->id)['available']);
    }

    public function test_dashboard_aggregates_real_available_metrics_only(): void
    {
        $user = $this->actingUser();
        [$post, $page] = $this->makePostWithPage($user);

        [$unavailablePost, $unavailablePage] = $this->makePostWithPage($user);

        $this->asOrganizationOf($user, function () use ($post, $page, $unavailablePost, $unavailablePage): void {
            PostMetric::query()->create([
                'post_id' => $post->id, 'social_page_id' => $page->id, 'provider' => 'facebook',
                'is_available' => true, 'impressions' => 1000, 'reach' => 500, 'clicks' => 40, 'reactions' => 30, 'shares' => 10, 'comments' => 20,
            ]);

            PostMetric::query()->create([
                'post_id' => $unavailablePost->id, 'social_page_id' => $unavailablePage->id, 'provider' => 'instagram',
                'is_available' => false, 'impressions' => 999, 'reach' => 999,
            ]);
        });

        $response = $this->getJson('/api/v1/analytics/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.total_reach', 500)
            ->assertJsonPath('data.total_impressions', 1000)
            ->assertJsonPath('data.total_engagement', 100)
            ->assertJsonPath('data.best_platform', null)
            ->assertJsonPath('data.best_publish_hour', null);

        $this->assertCount(1, $response->json('data.top_posts'));
    }

    public function test_best_platform_and_best_hour_stay_null_below_the_sample_size_threshold(): void
    {
        $user = $this->actingUser();
        [$post, $page] = $this->makePostWithPage($user);

        $this->asOrganizationOf($user, function () use ($post, $page): void {
            PostMetric::query()->create([
                'post_id' => $post->id, 'social_page_id' => $page->id, 'provider' => 'facebook',
                'is_available' => true, 'impressions' => 100, 'reach' => 100, 'clicks' => 5, 'reactions' => 5,
            ]);
        });

        $response = $this->getJson('/api/v1/analytics/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.best_platform', null)
            ->assertJsonPath('data.best_publish_hour', null);
    }

    public function test_best_platform_and_best_hour_populate_once_enough_samples_exist(): void
    {
        $user = $this->actingUser();

        for ($i = 0; $i < 3; $i++) {
            [$post, $page] = $this->makePostWithPage($user, 'facebook');

            $this->asOrganizationOf($user, function () use ($post, $page): void {
                PostMetric::query()->create([
                    'post_id' => $post->id, 'social_page_id' => $page->id, 'provider' => 'facebook',
                    'is_available' => true, 'impressions' => 100, 'reach' => 100, 'clicks' => 20, 'reactions' => 20,
                ]);

                $account = $page->socialAccount;
                PostPublicationAttempt::query()->create([
                    'post_id' => $post->id,
                    'social_account_id' => $account->id,
                    'social_page_id' => $page->id,
                    'idempotency_key' => 'key-best-'.$post->id,
                    'attempt_number' => 1,
                    'status' => 'success',
                    'processed_at' => now()->setTime(14, 0),
                ]);
            });
        }

        for ($i = 0; $i < 2; $i++) {
            [$post, $page] = $this->makePostWithPage($user, 'instagram');

            $this->asOrganizationOf($user, function () use ($post, $page): void {
                PostMetric::query()->create([
                    'post_id' => $post->id, 'social_page_id' => $page->id, 'provider' => 'instagram',
                    'is_available' => true, 'impressions' => 100, 'reach' => 100, 'clicks' => 1, 'reactions' => 1,
                ]);
            });
        }

        $response = $this->getJson('/api/v1/analytics/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.best_platform', 'facebook')
            ->assertJsonPath('data.best_publish_hour', 14);
    }
}
