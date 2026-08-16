<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sprint 4 (Commercial SaaS) regression coverage: the default Free plan
 * auto-assignment done by PersonalOrganizationProvisioner, and the two new
 * quota-enforcement checkpoints (PostController::assertPublishQuotaAvailable,
 * SocialAccountController::rejectOverSocialAccountQuota). Mirrors the same
 * "no subscription row = unlimited" backward-compatible default already
 * proven in OrganizationEntitlementsTest — these tests prove the two real
 * call sites actually enforce it, not just the underlying support class.
 */
class PlansAndQuotasSprint4Test extends TestCase
{
    use RefreshDatabase;

    public function test_a_freshly_provisioned_organization_gets_a_free_plan_subscription_when_the_plan_exists(): void
    {
        $freePlan = Plan::query()->create([
            'name' => 'Free',
            'slug' => 'free',
            'limits' => ['max_team_members' => 5, 'max_social_accounts' => 3, 'max_scheduled_posts_per_month' => 30],
        ]);

        // User::factory()->create() fires User::booted()'s created hook with
        // no active TenantContext, which is exactly the fresh-registration
        // path PersonalOrganizationProvisioner exists for.
        $user = User::factory()->create();

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $user->current_organization_id)
            ->first();

        $this->assertNotNull($subscription, 'a new organization must be auto-subscribed to the free plan when it exists');
        $this->assertSame($freePlan->id, $subscription->plan_id);
        $this->assertTrue($subscription->isActiveOrTrialing());
    }

    public function test_a_freshly_provisioned_organization_gets_a_free_plan_subscription_even_when_no_plan_row_existed_yet(): void
    {
        // No Plan row at all beforehand — simulates a completely fresh,
        // unseeded database (PlanSeeder never ran). Must NOT fall back to
        // "no subscription = unlimited": PersonalOrganizationProvisioner
        // guarantees the Free plan exists (auto-creating it via
        // DefaultPlans) rather than depending on deployment scripts to
        // have seeded it first — this is the fix for a real gap found in
        // live testing (a clean database left every quota silently
        // unenforced until someone remembered to run db:seed).
        $this->assertSame(0, Plan::query()->count());

        $user = User::factory()->create();

        $this->assertSame(1, Plan::query()->where('slug', 'free')->count());

        $subscription = OrganizationSubscription::query()
            ->where('organization_id', $user->current_organization_id)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->isActiveOrTrialing());
        $this->assertSame(5, $subscription->plan->usageLimit('max_team_members'));
        $this->assertSame(3, $subscription->plan->usageLimit('max_social_accounts'));
        $this->assertSame(30, $subscription->plan->usageLimit('max_scheduled_posts_per_month'));
    }

    public function test_provisioning_two_organizations_reuses_the_same_auto_created_free_plan(): void
    {
        User::factory()->create();
        User::factory()->create();

        // firstOrCreate() must not create a duplicate 'free' plan on the
        // second organization's provisioning.
        $this->assertSame(1, Plan::query()->where('slug', 'free')->count());
    }

    public function test_admin_user_seeder_also_assigns_the_free_plan_through_the_shared_provisioner(): void
    {
        Plan::query()->create([
            'name' => 'Free',
            'slug' => 'free',
            'limits' => ['max_team_members' => 5],
        ]);

        // Mirrors AdminUserSeederTest's own pattern: the test environment
        // loads the real .env (there is no .env.testing), so ADMIN_EMAIL/
        // ADMIN_PASSWORD resolve to whatever is actually configured there
        // rather than anything overridable at runtime — read the same
        // fallback-defaulted value back out instead of fighting env().
        (new AdminUserSeeder)->run();

        $admin = User::query()->where('email', env('ADMIN_EMAIL', 'admin@smartpublisher.local'))->firstOrFail();

        $this->assertTrue(
            OrganizationSubscription::query()->where('organization_id', $admin->current_organization_id)->exists(),
            'AdminUserSeeder must provision the same free-plan subscription as the normal registration path',
        );
    }

    private function subscribeToLimitedPlan(User $user, array $limits): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Limited plan '.uniqid(),
            'slug' => 'limited-'.uniqid(),
            'limits' => $limits,
        ]);

        // PersonalOrganizationProvisioner now guarantees every freshly
        // created organization already has a Free-plan subscription (see
        // the guarantee test above) — organization_subscriptions.
        // organization_id is unique, so a bare create() here would throw a
        // constraint violation. Replace it instead: a real organization
        // only ever has one active subscription at a time anyway.
        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $user->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active'],
        );

        return $plan;
    }

    /**
     * @return array{0: Post, 1: SocialPage}
     */
    private function makeDraftPostWithFacebookTarget(User $user): array
    {
        return $this->asOrganizationOf($user, function () use ($user) {
            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'Sprint 4 quota post '.uniqid(),
                'content' => 'Body',
                'status' => 'draft',
            ]);

            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'quota-account-'.$post->id,
                'access_token' => 'test-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'quota-page-'.$post->id,
                'name' => 'Facebook Page',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $post->socialPages()->sync([$page->id]);

            return [$post, $page];
        });
    }

    public function test_schedule_endpoint_rejects_once_the_monthly_post_quota_is_reached(): void
    {
        $user = User::factory()->create();
        $this->subscribeToLimitedPlan($user, ['max_scheduled_posts_per_month' => 1]);

        [$firstPost] = $this->makeDraftPostWithFacebookTarget($user);
        [$secondPost] = $this->makeDraftPostWithFacebookTarget($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$firstPost->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertOk();

        $this->postJson('/api/v1/posts/'.$secondPost->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('errors.post.0', 'Your organization has reached its scheduled/published post limit for the current plan this month.');

        $this->assertSame('draft', $secondPost->fresh()->status);
    }

    public function test_publish_now_endpoint_rejects_once_the_monthly_post_quota_is_reached(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1'], 200)]);
        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $user = User::factory()->create();
        $this->subscribeToLimitedPlan($user, ['max_scheduled_posts_per_month' => 1]);

        [$firstPost] = $this->makeDraftPostWithFacebookTarget($user);
        [$secondPost] = $this->makeDraftPostWithFacebookTarget($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$firstPost->id.'/publish-now')->assertOk();

        $this->postJson('/api/v1/posts/'.$secondPost->id.'/publish-now')
            ->assertStatus(422)
            ->assertJsonPath('errors.post.0', 'Your organization has reached its scheduled/published post limit for the current plan this month.');

        $this->assertSame('draft', $secondPost->fresh()->status);
    }

    public function test_schedule_endpoint_still_works_when_the_organization_has_no_subscription(): void
    {
        // A freshly provisioned organization now always gets a Free-plan
        // subscription (see the guarantee tests above) — this test exists
        // for the case that still matters in production: an organization
        // that predates Sprint 4 entirely and genuinely has no
        // subscription row. Deleting the auto-created one reproduces that
        // legacy state; the backward-compatible "unlimited" default must
        // still let real scheduling through unmodified.
        $user = User::factory()->create();
        OrganizationSubscription::query()->where('organization_id', $user->current_organization_id)->delete();
        [$post] = $this->makeDraftPostWithFacebookTarget($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/posts/'.$post->id.'/schedule', [
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertOk();

        $this->assertSame('scheduled', $post->fresh()->status);
    }

    // test_connecting_a_social_account_rejects_once_the_social_account_quota_is_reached
    // was removed in Sprint C (role/permission remediation): it exercised
    // the generic store() endpoint, which no longer exists (see
    // SocialAccountController's removal docblock — every real connection
    // now goes through OAuth or connectTelegramBot()).
    // test_connecting_a_telegram_bot_rejects_once_the_social_account_quota_is_reached
    // below already proves the exact same quota-rejection behavior via a
    // real remaining endpoint.

    public function test_reconnecting_the_same_telegram_bot_is_not_blocked_by_the_quota(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555_111, 'is_bot' => true, 'username' => 'reconnect_test_bot'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->subscribeToLimitedPlan($user, ['max_social_accounts' => 1]);

        $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_account_id' => '555111',
            'access_token' => 'old-bot-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        Sanctum::actingAs($user);

        // Same bot (same Telegram numeric id returned by getMe) as the
        // already-owned account: updateOrCreate() hits the existing row —
        // a re-sync, not a net-new connection — so it must not be counted
        // against the quota.
        // 2026-08-16: a re-sync of an already-owned account now correctly
        // reports 200 "updated" rather than 201 "created".
        $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'new-bot-token',
        ])->assertOk()->assertJsonPath('data.provider_account_id', '555111');
    }

    public function test_connecting_a_telegram_bot_rejects_once_the_social_account_quota_is_reached(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 987_654, 'is_bot' => true, 'username' => 'quota_test_bot'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $this->subscribeToLimitedPlan($user, ['max_social_accounts' => 1]);

        $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_account_id' => 'already-connected',
            'access_token' => 'test-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'test-bot-token',
        ])->assertStatus(422)->assertJsonPath('errors.code', 'social_account_quota_exceeded');

        $this->assertDatabaseMissing('social_accounts', ['provider' => 'telegram']);
    }

    public function test_connecting_a_social_account_still_works_when_the_organization_has_no_subscription(): void
    {
        // Same reasoning as the schedule-endpoint test above: reproduces
        // the legacy pre-Sprint-4 "no subscription row" state explicitly,
        // since a freshly provisioned organization no longer starts in it.
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 222_333, 'is_bot' => true, 'username' => 'unlimited_org_bot'],
            ], 200),
        ]);

        $user = User::factory()->create();
        OrganizationSubscription::query()->where('organization_id', $user->current_organization_id)->delete();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'unlimited-org-bot-token',
        ])->assertCreated();
    }
}
