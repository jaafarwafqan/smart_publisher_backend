<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPostMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Returns [PostPublicationAttempt, User] — the User is needed by callers
     * so they can re-enter that same organization's TenantContext afterward
     * (via asOrganizationOf) when asserting on PostMetric rows, since the
     * command itself only holds tenant context for the duration of its own
     * per-attempt TenantContext::run() closure.
     */
    private function makeAttempt(string $provider, array $providerResponse, ?Carbon $processedAt = null): array
    {
        $user = User::factory()->create();

        $attempt = $this->asOrganizationOf($user, function () use ($user, $provider, $providerResponse, $processedAt) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'A post',
                'status' => 'published',
            ]);

            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_account_id' => 'acc-'.$post->id,
                'access_token' => 'token-'.$post->id,
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'page-'.$post->id,
                'name' => 'A page',
                'status' => 'valid',
            ]);

            $attempt = PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'social_page_id' => $page->id,
                'idempotency_key' => 'key-'.$post->id,
                'attempt_number' => 1,
                'status' => 'success',
                'provider_response' => json_encode($providerResponse),
                'processed_at' => $processedAt ?? now(),
            ]);

            return $attempt;
        });

        return [$attempt, $user];
    }

    public function test_it_fetches_real_facebook_metrics_and_stores_them(): void
    {
        Http::fake([
            'graph.facebook.com/fb-post-1/insights*' => Http::response([
                'data' => [
                    ['name' => 'post_impressions', 'values' => [['value' => 1000]]],
                    ['name' => 'post_impressions_unique', 'values' => [['value' => 800]]],
                    ['name' => 'post_clicks', 'values' => [['value' => 50]]],
                    ['name' => 'post_reactions_by_type_total', 'values' => [['value' => ['like' => 20, 'love' => 5]]]],
                ],
            ], 200),
            'graph.facebook.com/fb-post-1?*' => Http::response([
                'shares' => ['count' => 3],
                'comments' => ['summary' => ['total_count' => 7]],
            ], 200),
        ]);

        [$attempt, $user] = $this->makeAttempt('facebook', ['provider_post_id' => 'fb-post-1']);

        $this->artisan('post-metrics:sync')->assertExitCode(0);

        $this->asOrganizationOf($user, function () use ($attempt): void {
            $metric = PostMetric::query()->where('post_id', $attempt->post_id)->firstOrFail();
            $this->assertTrue($metric->is_available);
            $this->assertSame(1000, $metric->impressions);
            $this->assertSame(800, $metric->reach);
            $this->assertSame(50, $metric->clicks);
            $this->assertSame(25, $metric->reactions);
            $this->assertSame(3, $metric->shares);
            $this->assertSame(7, $metric->comments);
            $this->assertSame('facebook', $metric->provider);
        });
    }

    public function test_it_marks_metrics_unavailable_when_facebook_insights_fetch_fails(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token']], 400),
        ]);

        [$attempt, $user] = $this->makeAttempt('facebook', ['provider_post_id' => 'fb-post-2']);

        $this->artisan('post-metrics:sync')->assertExitCode(0);

        $this->asOrganizationOf($user, function () use ($attempt): void {
            $metric = PostMetric::query()->where('post_id', $attempt->post_id)->firstOrFail();
            $this->assertFalse($metric->is_available);
        });
    }

    public function test_it_reports_unavailable_for_a_provider_with_no_real_metrics_integration(): void
    {
        [$attempt, $user] = $this->makeAttempt('telegram', ['provider_post_id' => '123']);

        $this->artisan('post-metrics:sync')->assertExitCode(0);

        $this->asOrganizationOf($user, function () use ($attempt): void {
            $metric = PostMetric::query()->where('post_id', $attempt->post_id)->firstOrFail();
            $this->assertFalse($metric->is_available);
            $this->assertSame(0, $metric->impressions);
        });
    }

    public function test_it_skips_attempts_older_than_seven_days(): void
    {
        [$attempt] = $this->makeAttempt('facebook', ['provider_post_id' => 'fb-post-old'], now()->subDays(10));

        $this->artisan('post-metrics:sync')->assertExitCode(0);

        $this->assertDatabaseMissing('post_metrics', ['post_id' => $attempt->post_id]);
    }
}
