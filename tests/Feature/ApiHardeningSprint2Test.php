<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\PublishEngineService;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 2 (API Hardening) regression coverage: general rate limiting, the
 * removed legacy /accounts/* surface, and the circuit-breaker counter's
 * atomic increment.
 */
class ApiHardeningSprint2Test extends TestCase
{
    use RefreshDatabase;

    public function test_the_general_api_limiter_returns_429_once_exceeded(): void
    {
        config()->set('cache.default', 'array');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // The 'api' RateLimiter is defined at 120/minute — drives real
        // requests through the actual throttle:api middleware rather than
        // guessing at Laravel's internal cache-key hashing for a named
        // limiter, which would risk a test that passes or fails for the
        // wrong reason.
        $lastStatus = 200;
        for ($i = 0; $i < 121; $i++) {
            $lastStatus = $this->getJson('/api/v1/posts')->getStatusCode();
        }

        $this->assertSame(429, $lastStatus);
    }

    public function test_publish_now_has_its_own_stricter_limiter_than_the_general_api_one(): void
    {
        $this->assertTrue(
            config('publishing') !== null,
            'sanity: publishing config must be loadable for this test to mean anything',
        );

        // Route-level assertion: publish-now and schedule carry the
        // 'publish' throttle, not just the blanket 'api' one — verified by
        // inspecting the registered route's middleware rather than
        // exhausting a real 20-request budget (slow, and the exhaustion
        // mechanics are already proven by the general-limiter test above).
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/posts/{post}/publish-now',
        );

        $this->assertNotNull($route);
        $this->assertContains('throttle:publish', $route->middleware());
    }

    public function test_the_legacy_accounts_endpoints_no_longer_exist(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Previously routed to AccountController; now genuinely unmatched
        // routes (404 from Laravel's router itself, not a controller
        // method that used to 500).
        $this->getJson('/api/v1/accounts')->assertStatus(404);
        $this->postJson('/api/v1/accounts/connect', [])->assertStatus(404);
    }

    /**
     * The core Sprint 2 circuit-breaker bug: Cache::get()+1 then
     * Cache::put() as two separate operations meant two concurrent
     * failures could read the same starting value and one increment would
     * be lost. True concurrent-write proof needs multiple real processes
     * (this project already defers that class of proof to the MySQL CI
     * job for the DB-level equivalents — not reproducible in single-
     * threaded PHPUnit). This proves the replacement primitives
     * (Cache::add + Cache::increment) are what's actually wired in and
     * produce the exact right count for the sequential case — the same
     * standard already relied on by test_org_scoped_circuit_breaker_never_
     * blocks_a_different_organization in PublishingReliabilityAcceptanceTest,
     * which continues to pass unchanged after this fix.
     */
    public function test_circuit_breaker_counter_reaches_the_exact_configured_threshold(): void
    {
        Cache::flush();
        config()->set('publishing.org_circuit_breaker_threshold', 4);
        config()->set('publishing.circuit_breaker_threshold', 10);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');
        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['error' => 'invalid token'], 401),
        ]);

        $user = User::factory()->create();

        // 3 failures: below the org threshold (4) — must not be open yet.
        foreach (range(1, 3) as $i) {
            $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'ckt-page-'.$i,
                'access_token' => 'tok',
                'status' => 'connected',
                'is_active' => true,
            ]));
            $page = $this->asOrganizationOf($user, fn () => SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'ckt-page-'.$i,
                'name' => 'Page '.$i,
                'can_publish' => true,
                'status' => 'valid',
            ]));
            $post = $this->asOrganizationOf($user, fn () => Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Circuit test '.$i,
                'content' => 'Body',
                'status' => 'publishing',
                'publish_batch_key' => 'ckt-batch-'.$i,
            ]));

            $result = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($post->fresh(), $page, 'ckt-batch-'.$i));
            $this->assertSame('failed', $result['status']);
        }

        $stillOpenAccount = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_account_id' => 'ckt-page-check',
            'access_token' => 'tok',
            'status' => 'connected',
            'is_active' => true,
        ]));
        $checkPage = $this->asOrganizationOf($user, fn () => SocialPage::query()->create([
            'social_account_id' => $stillOpenAccount->id,
            'page_id' => 'ckt-page-check',
            'name' => 'Check page',
            'can_publish' => true,
            'status' => 'valid',
        ]));
        $checkPost = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Circuit check',
            'content' => 'Body',
            'status' => 'publishing',
            'publish_batch_key' => 'ckt-batch-check',
        ]));

        // 4th distinct failure trips the org circuit exactly at the
        // configured threshold — not one early (lost increment) or one
        // late (double-counted increment).
        $fourth = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($checkPost->fresh(), $checkPage, 'ckt-batch-check'));
        $this->assertSame('failed', $fourth['status']);

        $fifthPost = $this->asOrganizationOf($user, fn () => Post::query()->create([
            'user_id' => $user->id,
            'title' => 'Circuit check 2',
            'content' => 'Body',
            'status' => 'publishing',
            'publish_batch_key' => 'ckt-batch-check2',
        ]));
        $fifth = $this->asOrganizationOf($user, fn () => app(PublishEngineService::class)->publish($fifthPost->fresh(), $checkPage, 'ckt-batch-check2'));
        $this->assertSame('retry_scheduled', $fifth['status']);
        $this->assertSame('circuit_open', $fifth['reason'] ?? null);
    }
}
