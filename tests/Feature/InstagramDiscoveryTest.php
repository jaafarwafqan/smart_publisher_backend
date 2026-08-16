<?php

namespace Tests\Feature;

use App\Infrastructure\ExternalServices\Publishing\SocialPageSyncService;
use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_pages_returns_the_linked_instagram_business_account_alongside_the_facebook_page(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id' => 'page-1',
                        'name' => 'My Business Page',
                        'access_token' => 'page-token-1',
                        'tasks' => ['CREATE_CONTENT'],
                        'instagram_business_account' => [
                            'id' => 'ig-1',
                            'username' => 'my_business',
                            'profile_picture_url' => 'https://example.com/ig.png',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $pages = app(SocialOAuthManager::class)->provider('facebook')->listPages('fb-token', []);

        $this->assertCount(2, $pages);

        $facebookPage = collect($pages)->firstWhere('page_id', 'page-1');
        $this->assertSame('My Business Page', $facebookPage['name']);
        $this->assertArrayNotHasKey('kind', $facebookPage);
        // 2026-08-12: capturing this is what makes real publishing work —
        // Meta requires this exact page-scoped token to create content ON
        // the page; the account-level user token gets a generic rejection.
        $this->assertSame('page-token-1', $facebookPage['access_token']);

        $instagramPage = collect($pages)->firstWhere('page_id', 'ig-1');
        $this->assertSame('instagram_business', $instagramPage['kind']);
        $this->assertSame('my_business', $instagramPage['name']);
        $this->assertTrue($instagramPage['can_publish']);
        $this->assertSame('page-1', $instagramPage['metadata']['parent_page_id']);
        // 2026-08: InstagramProvider now makes real Content Publishing API
        // calls, authenticated with the SAME linked Page's access token —
        // there's no separate Instagram-scoped token to request. Reuses the
        // parent page's own token instead of the null this used to assert
        // (see FacebookOAuthProviderPagesTest for the unit-level version of
        // this same fix).
        $this->assertSame('page-token-1', $instagramPage['access_token']);
    }

    public function test_list_pages_returns_only_the_facebook_page_when_no_instagram_account_is_linked(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page-2', 'name' => 'Plain Page', 'access_token' => 'token', 'tasks' => ['CREATE_CONTENT']],
                ],
            ], 200),
        ]);

        $pages = app(SocialOAuthManager::class)->provider('facebook')->listPages('fb-token', []);

        $this->assertCount(1, $pages);
        $this->assertSame('page-2', $pages[0]['page_id']);
    }

    public function test_syncing_a_facebook_account_creates_a_social_page_row_for_the_linked_instagram_account(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id' => 'page-3',
                        'name' => 'Another Page',
                        'access_token' => 'page-token-3',
                        'tasks' => ['CREATE_CONTENT'],
                        'instagram_business_account' => ['id' => 'ig-3', 'username' => 'another_biz'],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-3',
            'access_token' => 'fb-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        $this->asOrganizationOf($user, fn () => app(SocialPageSyncService::class)->syncAuto($account));

        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $account->id,
            'page_id' => 'ig-3',
            'kind' => 'instagram_business',
            'name' => 'another_biz',
        ]);
        $this->assertDatabaseHas('social_pages', [
            'social_account_id' => $account->id,
            'page_id' => 'page-3',
            'kind' => 'page',
        ]);

        $this->assertSame(2, $this->asOrganizationOf($user, fn () => SocialPage::query()->where('social_account_id', $account->id)->count()));

        // Not assertDatabaseHas — access_token is 'encrypted' (SocialPage::casts()),
        // so the stored column is ciphertext; must fetch the model and read
        // the auto-decrypted attribute to verify the real value round-tripped.
        $stored = $this->asOrganizationOf(
            $user,
            fn () => SocialPage::query()->where('social_account_id', $account->id)->where('page_id', 'page-3')->firstOrFail(),
        );
        $this->assertSame('page-token-3', $stored->access_token);
    }

    /**
     * A re-sync response can legitimately omit access_token for an entry
     * that never has one (Instagram Business, above) — that must never be
     * read as "clear the Facebook page's already-captured real token back
     * to null." SocialPageSyncService::syncAuto() only overwrites it when
     * the new response actually carries a non-null value.
     */
    public function test_resyncing_never_clears_an_already_captured_page_token(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page-4', 'name' => 'Stable Page', 'access_token' => 'original-token', 'tasks' => ['CREATE_CONTENT']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->asOrganizationOf($user, fn () => SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'discovery_mode' => 'auto',
            'provider_account_id' => 'fb-user-4',
            'access_token' => 'fb-token',
            'status' => 'connected',
            'is_active' => true,
        ]));

        $this->asOrganizationOf($user, fn () => app(SocialPageSyncService::class)->syncAuto($account));

        // Simulate a second sync whose Graph response happens not to carry
        // access_token at all for this entry (a real-world possibility, not
        // just a test artifact — e.g. a partial/degraded Graph response).
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    ['id' => 'page-4', 'name' => 'Stable Page', 'tasks' => ['CREATE_CONTENT']],
                ],
            ], 200),
        ]);

        $this->asOrganizationOf($user, fn () => app(SocialPageSyncService::class)->syncAuto($account));

        $stored = $this->asOrganizationOf(
            $user,
            fn () => SocialPage::query()->where('social_account_id', $account->id)->where('page_id', 'page-4')->firstOrFail(),
        );
        $this->assertSame('original-token', $stored->access_token);
    }
}
