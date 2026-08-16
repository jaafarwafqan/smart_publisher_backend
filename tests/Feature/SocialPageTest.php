<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SocialPageTest extends TestCase
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

    public function test_connect_telegram_bot_creates_a_social_account(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555, 'is_bot' => true, 'username' => 'smart_publisher_bot'],
            ], 200),
        ]);

        $user = $this->actingUser(['social-accounts.create']);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => '123:ABC',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'telegram')
            ->assertJsonPath('data.account_username', '@smart_publisher_bot')
            ->assertJsonPath('data.discovery_mode', 'manual');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_account_id' => '555',
        ]);
    }

    public function test_connect_telegram_bot_rejects_an_invalid_token(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => false], 401),
        ]);

        $user = $this->actingUser(['social-accounts.create']);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => 'bad-token',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id, 'provider' => 'telegram']);
    }

    public function test_connect_telegram_bot_rejects_a_bot_already_linked_to_a_different_organization(): void
    {
        // (provider, provider_account_id) is unique platform-wide, but the
        // "is it already linked?" lookup — and updateOrCreate's own match
        // clause — are implicitly scoped to the caller's own organization
        // via SocialAccount's OrganizationScope. Reproduced live
        // 2026-08-16: a bot already connected to a different organization
        // was invisible to that scoped lookup, so the endpoint attempted an
        // INSERT that collided with the real DB constraint and surfaced as
        // an uncaught 500 with no indication of the real cause.
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555, 'is_bot' => true, 'username' => 'smart_publisher_bot'],
            ], 200),
        ]);

        $firstOrgUser = $this->actingUser(['social-accounts.create']);
        $this->telegramAccount($firstOrgUser);

        $secondOrgUser = $this->actingUser(['social-accounts.create']);

        $response = $this->postJson('/api/v1/users/'.$secondOrgUser->id.'/social-accounts/telegram/connect', [
            'bot_token' => '123:ABC',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'social_account_already_linked_elsewhere');

        $this->assertDatabaseMissing('social_accounts', [
            'user_id' => $secondOrgUser->id,
            'provider' => 'telegram',
        ]);
    }

    public function test_connect_telegram_bot_updates_the_existing_account_on_reconnect(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555, 'is_bot' => true, 'username' => 'smart_publisher_bot'],
            ], 200),
        ]);

        $user = $this->actingUser(['social-accounts.create']);
        $existing = $this->telegramAccount($user);

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/telegram/connect', [
            'bot_token' => '123:ABC',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Telegram bot updated successfully.')
            ->assertJsonPath('data.id', $existing->id);

        $this->assertDatabaseCount('social_accounts', 1);
    }

    private function telegramAccount(User $user): SocialAccount
    {
        return $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'discovery_mode' => 'manual',
            'provider_account_id' => '555',
            'account_name' => 'smart_publisher_bot',
            'access_token' => '123:ABC',
            'status' => 'connected',
            'is_active' => true,
        ]));
    }

    public function test_add_page_verifies_and_creates_a_channel_when_bot_is_admin(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response([
                'ok' => true,
                'result' => 1200,
            ], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator', 'can_post_messages' => true],
            ], 200),
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555],
            ], 200),
            'api.telegram.org/bot*/getChat*' => Http::response([
                'ok' => true,
                'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'username' => 'nursing_kufa', 'type' => 'channel'],
            ], 200),
        ]);

        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/add',
            ['identifier' => '@nursing_kufa']
        );

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Nursing Channel')
            ->assertJsonPath('data.can_publish', true)
            ->assertJsonPath('data.status', 'valid');

        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $account->id,
            'page_id' => '-1001',
            'kind' => 'channel',
        ]);
    }

    public function test_add_page_treats_inaccessible_member_list_as_usable_when_get_chat_succeeds(): void
    {
        // Real-world Telegram behavior: getChatMember can fail with "member
        // list is inaccessible" for a channel's own bot depending on the
        // channel's privacy settings, even when the bot really is an admin
        // with post rights. getChat succeeding is what actually proves access.
        Http::fake([
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response(['ok' => false, 'error_code' => 400], 400),
            'api.telegram.org/bot*/getChatMember*' => Http::response(
                ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: member list is inaccessible'],
                400
            ),
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555],
            ], 200),
            'api.telegram.org/bot*/getChat*' => Http::response([
                'ok' => true,
                'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'username' => 'nursing_kufa', 'type' => 'channel'],
            ], 200),
        ]);

        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/add',
            ['identifier' => '@nursing_kufa']
        );

        $response->assertCreated()
            ->assertJsonPath('data.can_publish', true)
            ->assertJsonPath('data.status', 'valid');
    }

    public function test_add_page_marks_needs_reauth_when_bot_is_not_admin(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response([
                'ok' => true,
                'result' => 10,
            ], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ], 200),
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 555],
            ], 200),
            'api.telegram.org/bot*/getChat*' => Http::response([
                'ok' => true,
                'result' => ['id' => -1002, 'title' => 'Other Channel', 'type' => 'channel'],
            ], 200),
        ]);

        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/add',
            ['identifier' => '-1002']
        );

        $response->assertCreated()
            ->assertJsonPath('data.can_publish', false)
            ->assertJsonPath('data.status', 'needs_reauth');
    }

    public function test_add_page_fails_when_channel_is_not_found(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChat*' => Http::response(['ok' => false], 400),
        ]);

        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/add',
            ['identifier' => '@does_not_exist']
        );

        $response->assertStatus(422);
    }

    public function test_destroy_page_removes_it_and_rejects_pages_from_another_account(): void
    {
        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        [$page, $otherPage] = $this->asOrganizationOf($user, function () use ($user, $account) {
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => '-1001',
                'kind' => 'channel',
                'name' => 'Nursing Channel',
                'status' => 'valid',
            ]);

            $otherAccount = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'discovery_mode' => 'manual',
                'provider_account_id' => '556',
                'account_name' => 'other_bot',
                'access_token' => '456:DEF',
                'status' => 'connected',
                'is_active' => true,
            ]);
            $otherPage = SocialPage::query()->create([
                'social_account_id' => $otherAccount->id,
                'page_id' => '-2002',
                'kind' => 'channel',
                'name' => 'Other Channel',
                'status' => 'valid',
            ]);

            return [$page, $otherPage];
        });

        $mismatchResponse = $this->deleteJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/'.$otherPage->id
        );
        $mismatchResponse->assertStatus(404);
        $this->assertDatabaseHas('social_pages', ['id' => $otherPage->id]);

        $response = $this->deleteJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/'.$page->id
        );

        $response->assertOk();
        $this->assertDatabaseMissing('social_pages', ['id' => $page->id]);
    }

    public function test_list_pages_returns_the_accounts_pages(): void
    {
        $user = $this->actingUser(['social-accounts.pages.view']);
        $account = $this->telegramAccount($user);

        $this->asOrganizationOf($user, fn () => SocialPage::query()->create([
            'social_account_id' => $account->id,
            'page_id' => '-1001',
            'kind' => 'channel',
            'name' => 'Nursing Channel',
            'can_publish' => true,
            'status' => 'valid',
        ]));

        $response = $this->getJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * 2026-08-12: SocialPage.access_token (the real Facebook Page-scoped
     * token publishing now depends on) must never reach a client response —
     * transformPage() is a manual allowlist specifically so a future field
     * addition to the model can't silently leak through a blanket
     * ->toArray()/->toJson(). This locks that in as an explicit regression
     * test, not just an implicit property of the current code.
     */
    public function test_list_pages_never_exposes_the_page_access_token(): void
    {
        $user = $this->actingUser(['social-accounts.pages.view']);
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-1',
            'access_token' => 'user-token',
            'status' => 'connected',
            'is_active' => true,
        ]));
        $this->asOrganizationOf($user, fn () => SocialPage::query()->create([
            'social_account_id' => $account->id,
            'page_id' => 'page-1',
            'kind' => 'page',
            'name' => 'Test Page',
            'access_token' => 'super-secret-page-token',
            'can_publish' => true,
            'status' => 'valid',
        ]));

        $response = $this->getJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertArrayNotHasKey('access_token', $response->json('data.0'));
        $this->assertStringNotContainsString('super-secret-page-token', $response->getContent());
    }

    public function test_sync_pages_revalidates_manual_channels_and_invalidates_failures(): void
    {
        $user = $this->actingUser(['social-accounts.pages.sync']);
        $account = $this->telegramAccount($user);

        [$healthy, $revoked] = $this->asOrganizationOf($user, function () use ($account) {
            $healthy = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => '-1001',
                'kind' => 'channel',
                'name' => 'Nursing Channel',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            $revoked = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => '-1002',
                'kind' => 'channel',
                'name' => 'Revoked Channel',
                'can_publish' => true,
                'status' => 'valid',
                'is_selected' => true,
            ]);

            return [$healthy, $revoked];
        });

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'getChatMemberCount')) {
                return Http::response(['ok' => true, 'result' => 5], 200);
            }
            if (str_contains($url, 'getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]], 200);
            }
            if (str_contains($url, 'getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 555]], 200);
            }
            if (str_contains($url, 'chat_id=-1001')) {
                return Http::response(['ok' => true, 'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'type' => 'channel']], 200);
            }

            return Http::response(['ok' => false], 400);
        });

        $response = $this->postJson('/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/sync');

        $response->assertOk();

        $this->assertSame('valid', $healthy->fresh()->status);
        $this->assertSame('invalid', $revoked->fresh()->status);
        $this->assertFalse($revoked->fresh()->is_selected);
    }

    public function test_select_pages_flips_only_the_requested_ids(): void
    {
        $user = $this->actingUser(['social-accounts.pages.manage']);
        $account = $this->telegramAccount($user);

        [$pageA, $pageB] = $this->asOrganizationOf($user, function () use ($account) {
            $pageA = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'a', 'name' => 'A', 'status' => 'valid',
            ]);
            $pageB = SocialPage::query()->create([
                'social_account_id' => $account->id, 'page_id' => 'b', 'name' => 'B', 'status' => 'valid', 'is_selected' => true,
            ]);

            return [$pageA, $pageB];
        });

        $response = $this->postJson(
            '/api/v1/users/'.$user->id.'/social-accounts/'.$account->id.'/pages/select',
            ['page_ids' => [$pageA->id]]
        );

        $response->assertOk();
        $this->assertTrue($pageA->fresh()->is_selected);
        $this->assertFalse($pageB->fresh()->is_selected);
    }
}
