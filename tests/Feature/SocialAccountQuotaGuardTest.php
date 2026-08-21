<?php

namespace Tests\Feature;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Billing\QuotaGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * 2026-08 quota-gap review: max_social_accounts used to be checked in
 * exactly one place (TelegramBotConnector), counting SocialAccount rows —
 * Facebook/Instagram (callback()/nativeConnect()) bypassed it completely.
 * SocialAccountQuotaGuard is now the single shared enforcement point, and
 * the counted unit changed to SocialPage rows with is_selected = true (the
 * actual publish destinations a plan bounds, not the OAuth account used to
 * discover them) — see the guard's own docblock. These tests prove the
 * rejection for every provider family this affects, not just Telegram.
 */
class SocialAccountQuotaGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
        $user->givePermissionTo($permissions);

        Sanctum::actingAs($user);

        return $user;
    }

    private function subscribeToLimitedPlan(User $user, int $maxSocialAccounts): void
    {
        $plan = Plan::query()->create([
            'name' => 'Limited plan '.uniqid(),
            'slug' => 'limited-'.uniqid(),
            'limits' => array_replace(QuotaGates::fallbackAll(), [QuotaGates::SOCIAL_ACCOUNTS => $maxSocialAccounts]),
        ]);

        OrganizationSubscription::query()->updateOrCreate(
            ['organization_id' => $user->current_organization_id],
            ['plan_id' => $plan->id, 'status' => 'active'],
        );
    }

    /** @return array{0: SocialAccount, 1: SocialPage} */
    private function accountWithSelectedPage(User $user, string $provider = 'facebook'): array
    {
        return $this->asOrganizationOf($user, function () use ($user, $provider) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_account_id' => 'existing-'.$provider.'-'.uniqid(),
                'access_token' => 'test-token',
                'status' => 'connected',
                'is_active' => true,
            ]);

            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'existing-page-'.uniqid(),
                'name' => 'Already selected page',
                'can_publish' => true,
                'status' => 'valid',
                'is_selected' => true,
            ]);

            return [$account, $page];
        });
    }

    public function test_facebook_oauth_callback_rejects_a_new_connection_once_the_page_quota_is_reached(): void
    {
        $user = $this->actingUser(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);
        $this->subscribeToLimitedPlan($user, 1);
        $this->accountWithSelectedPage($user, 'facebook');

        config()->set('social.providers.facebook.client_id', 'client-id');
        config()->set('social.providers.facebook.client_secret', 'client-secret');
        config()->set('social.providers.facebook.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'fb-token', 'expires_in' => 3600], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'brand-new-fb-user', 'name' => 'New Page Admin'], 200),
        ]);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['pages_manage_posts'],
        ]);
        $state = $authorizeResponse->json('data.state');

        $callbackResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'facebook',
            'code' => 'facebook-auth-code',
            'state' => $state,
            'scopes' => ['pages_manage_posts'],
        ]);

        $callbackResponse->assertStatus(422)->assertJsonPath('errors.code', 'social_account_quota_exceeded');
        $this->assertDatabaseMissing('social_accounts', ['provider_account_id' => 'brand-new-fb-user']);
    }

    public function test_facebook_oauth_callback_allows_reauthorizing_an_existing_account_even_over_quota(): void
    {
        $user = $this->actingUser(['social-accounts.oauth.authorize', 'social-accounts.oauth.callback']);
        $this->subscribeToLimitedPlan($user, 1);
        [$account] = $this->accountWithSelectedPage($user, 'facebook');
        $account->update(['provider_account_id' => 'returning-fb-user']);

        config()->set('social.providers.facebook.client_id', 'client-id');
        config()->set('social.providers.facebook.client_secret', 'client-secret');
        config()->set('social.providers.facebook.token_url', 'https://graph.facebook.com/v20.0/oauth/access_token');

        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'fresh-fb-token', 'expires_in' => 3600], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'returning-fb-user', 'name' => 'Returning Admin'], 200),
        ]);

        $authorizeResponse = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/authorize', [
            'provider' => 'facebook',
            'redirect_uri' => 'smartpublisher://oauth/callback',
            'scopes' => ['pages_manage_posts'],
        ]);
        $state = $authorizeResponse->json('data.state');

        $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/callback', [
            'provider' => 'facebook',
            'code' => 'facebook-auth-code',
            'state' => $state,
            'scopes' => ['pages_manage_posts'],
        ])->assertOk();

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_native_connect_rejects_a_new_connection_once_the_page_quota_is_reached(): void
    {
        $user = User::factory()->create();
        $this->subscribeToLimitedPlan($user, 1);
        $this->accountWithSelectedPage($user, 'facebook');
        Sanctum::actingAs($user);

        config()->set('social.providers.facebook.client_id', 'app-id-123');
        config()->set('social.providers.facebook.client_secret', 'app-secret-xyz');

        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response([
                'data' => ['app_id' => 'app-id-123', 'is_valid' => true, 'expires_at' => 0, 'scopes' => []],
            ], 200),
            'graph.facebook.com/me*' => Http::response(['id' => 'brand-new-native-user', 'name' => 'New Native User'], 200),
        ]);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/native-connect', [
            'provider' => 'facebook',
            'access_token' => 'sdk-issued-token',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'social_account_quota_exceeded');
        $this->assertDatabaseMissing('social_accounts', ['provider_account_id' => 'brand-new-native-user']);
    }

    public function test_select_pages_rejects_selecting_more_pages_than_the_plan_allows(): void
    {
        $user = $this->actingUser(['social-accounts.pages.manage']);
        $this->subscribeToLimitedPlan($user, 1);

        [$account, $pageA, $pageB] = $this->asOrganizationOf($user, function () use ($user) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'select-pages-account',
                'access_token' => 'test-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $pageA = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'a', 'name' => 'Page A', 'status' => 'valid',
            ]);
            $pageB = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'b', 'name' => 'Page B', 'status' => 'valid',
            ]);

            return [$account, $pageA, $pageB];
        });

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/select',
            ['page_ids' => [$pageA->id, $pageB->id]],
        );

        $response->assertStatus(422)->assertJsonPath('errors.code', 'social_account_quota_exceeded');
        $this->assertFalse($pageA->fresh()->is_selected);
        $this->assertFalse($pageB->fresh()->is_selected);
    }

    public function test_select_pages_allows_selecting_exactly_up_to_the_plan_limit(): void
    {
        $user = $this->actingUser(['social-accounts.pages.manage']);
        $this->subscribeToLimitedPlan($user, 2);

        [$account, $pageA, $pageB] = $this->asOrganizationOf($user, function () use ($user) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'select-pages-account-2',
                'access_token' => 'test-token',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $pageA = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'a', 'name' => 'Page A', 'status' => 'valid',
            ]);
            $pageB = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'b', 'name' => 'Page B', 'status' => 'valid',
            ]);

            return [$account, $pageA, $pageB];
        });

        $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/select',
            ['page_ids' => [$pageA->id, $pageB->id]],
        )->assertOk();

        $this->assertTrue($pageA->fresh()->is_selected);
        $this->assertTrue($pageB->fresh()->is_selected);
    }

    public function test_add_telegram_channel_rejects_once_the_page_quota_is_reached(): void
    {
        $user = $this->actingUser(['social-accounts.create', 'social-accounts.pages.manage']);
        $this->subscribeToLimitedPlan($user, 1);
        $this->accountWithSelectedPage($user, 'facebook');

        $telegramAccount = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'discovery_mode' => 'manual',
            'provider_account_id' => '555',
            'access_token' => '123:ABC',
            'status' => 'connected',
            'is_active' => true,
        ]));

        Http::fake([
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response(['ok' => true, 'result' => 5], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]], 200),
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 555]], 200),
            'api.telegram.org/bot*/getChat*' => Http::response(['ok' => true, 'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'username' => 'nursing_kufa', 'type' => 'channel']], 200),
        ]);

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$telegramAccount->id.'/pages/add',
            ['identifier' => '@nursing_kufa'],
        );

        $response->assertStatus(422)->assertJsonPath('errors.code', 'social_account_quota_exceeded');
        $this->assertDatabaseMissing('social_pages', ['social_account_id' => $telegramAccount->id]);
    }

    public function test_add_telegram_channel_reverifying_an_already_selected_channel_is_not_blocked_by_the_quota(): void
    {
        $user = $this->actingUser(['social-accounts.create', 'social-accounts.pages.manage']);
        $this->subscribeToLimitedPlan($user, 1);

        $telegramAccount = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'discovery_mode' => 'manual',
            'provider_account_id' => '555',
            'access_token' => '123:ABC',
            'status' => 'connected',
            'is_active' => true,
        ]));
        $this->asOrganizationOf($user, fn () => SocialPage::query()->create([
            'social_account_id' => $telegramAccount->id,
            'page_id' => '-1001',
            'name' => 'Nursing Channel',
            'can_publish' => true,
            'status' => 'valid',
            'is_selected' => true,
        ]));

        Http::fake([
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response(['ok' => true, 'result' => 5], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]], 200),
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 555]], 200),
            'api.telegram.org/bot*/getChat*' => Http::response(['ok' => true, 'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'username' => 'nursing_kufa', 'type' => 'channel']], 200),
        ]);

        // Already at its own limit (1/1), but re-verifying the SAME
        // already-selected channel must not be rejected as if it were a new
        // one — this is exactly what happens after a channel needed reauth.
        $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$telegramAccount->id.'/pages/add',
            ['identifier' => '@nursing_kufa'],
        )->assertCreated();
    }
}
