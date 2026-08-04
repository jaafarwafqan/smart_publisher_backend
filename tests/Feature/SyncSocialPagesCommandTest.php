<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSocialPagesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: SocialAccount} */
    private function facebookAccount(): array
    {
        $user = User::factory()->create();

        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-1',
            'access_token' => 'fb-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        return [$user, $account];
    }

    /** @return array{0: User, 1: SocialAccount} */
    private function telegramAccountWithPage(): array
    {
        $user = User::factory()->create();

        $account = $this->asOrganizationOf($user, function () use ($user) {
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'telegram',
                'discovery_mode' => 'manual',
                'provider_account_id' => '555',
                'access_token' => '123:ABC',
                'status' => 'connected',
                'is_active' => true,
            ]);

            SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => '-1001',
                'kind' => 'channel',
                'name' => 'Nursing Channel',
                'can_publish' => true,
                'status' => 'valid',
            ]);

            return $account;
        });

        return [$user, $account];
    }

    public function test_it_syncs_auto_discovery_and_manual_accounts_in_one_run(): void
    {
        [$facebookUser, $facebookAccount] = $this->facebookAccount();
        [$telegramUser, $telegramAccount] = $this->telegramAccountWithPage();

        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page-1', 'name' => 'New Page', 'tasks' => ['CREATE_CONTENT']],
                ],
            ], 200),
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response(['ok' => true, 'result' => 5], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response(
                ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]],
                200
            ),
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 555]], 200),
            'api.telegram.org/bot*/getChat*' => Http::response(
                ['ok' => true, 'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'type' => 'channel']],
                200
            ),
        ]);

        $this->artisan('social-pages:sync')->assertExitCode(0);

        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $facebookAccount->id,
            'page_id' => 'page-1',
            'name' => 'New Page',
        ]);
        $this->asOrganizationOf($facebookUser, fn () => $this->assertNotNull($facebookAccount->fresh()->last_synced_at));

        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $telegramAccount->id,
            'page_id' => '-1001',
            'status' => 'valid',
        ]);
        $this->asOrganizationOf($telegramUser, fn () => $this->assertNotNull($telegramAccount->fresh()->last_synced_at));
    }

    public function test_it_skips_inactive_and_disconnected_accounts(): void
    {
        $inactiveUser = User::factory()->create();
        $inactive = $this->asOrganizationOf($inactiveUser, fn () => SocialAccount::query()->create([
            'user_id' => $inactiveUser->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-2',
            'access_token' => 'fb-token',
            'status' => 'connected',
            'is_active' => false,
        ]));

        $disconnectedUser = User::factory()->create();
        $disconnected = $this->asOrganizationOf($disconnectedUser, fn () => SocialAccount::query()->create([
            'user_id' => $disconnectedUser->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-3',
            'access_token' => 'fb-token',
            'status' => 'revoked',
            'is_active' => true,
        ]));

        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('social-pages:sync')->assertExitCode(0);

        $this->asOrganizationOf($inactiveUser, fn () => $this->assertNull($inactive->fresh()->last_synced_at));
        $this->asOrganizationOf($disconnectedUser, fn () => $this->assertNull($disconnected->fresh()->last_synced_at));
    }

    public function test_it_continues_syncing_other_accounts_after_one_fails(): void
    {
        [$failingUser, $failingAccount] = $this->facebookAccount();
        [$telegramUser, $telegramAccount] = $this->telegramAccountWithPage();

        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response(['error' => ['message' => 'Server error']], 500),
            'api.telegram.org/bot*/getChatMemberCount*' => Http::response(['ok' => true, 'result' => 5], 200),
            'api.telegram.org/bot*/getChatMember*' => Http::response(
                ['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]],
                200
            ),
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 555]], 200),
            'api.telegram.org/bot*/getChat*' => Http::response(
                ['ok' => true, 'result' => ['id' => -1001, 'title' => 'Nursing Channel', 'type' => 'channel']],
                200
            ),
        ]);

        $this->artisan('social-pages:sync')->assertExitCode(0);

        $this->asOrganizationOf($failingUser, fn () => $this->assertNull($failingAccount->fresh()->last_synced_at));
        $this->asOrganizationOf($telegramUser, fn () => $this->assertNotNull($telegramAccount->fresh()->last_synced_at));
    }
}
