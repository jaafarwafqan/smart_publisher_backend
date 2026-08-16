<?php

namespace Tests\Unit;

use App\Infrastructure\ExternalServices\SocialOAuth\FacebookOAuthProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookOAuthProviderPagesTest extends TestCase
{
    public function test_list_pages_maps_the_graph_response(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id' => 'page-1',
                        'name' => 'College of Nursing',
                        'picture' => ['data' => ['url' => 'https://example.com/pic1.jpg']],
                        'access_token' => 'page-token-1',
                        'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                    ],
                    [
                        'id' => 'page-2',
                        'name' => 'Personal Profile',
                        'tasks' => ['ANALYZE'],
                    ],
                ],
            ], 200),
        ]);

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());

        $pages = $provider->listPages('user-access-token', []);

        $this->assertCount(2, $pages);
        $this->assertSame('page-1', $pages[0]['page_id']);
        $this->assertSame('College of Nursing', $pages[0]['name']);
        $this->assertTrue($pages[0]['can_publish']);
        $this->assertArrayNotHasKey('page_access_token', $pages[0]['metadata']);

        $this->assertSame('page-2', $pages[1]['page_id']);
        $this->assertFalse($pages[1]['can_publish']);
    }

    /**
     * 2026-08: the linked Instagram Business Account entry used to hardcode
     * access_token: null ("out of scope" — Instagram publishing wasn't
     * implemented at all yet). Now that InstagramProvider makes real
     * Content Publishing API calls, it authenticates with the SAME Page
     * token Facebook Page posting uses — there is no separate
     * Instagram-scoped token to request, so the fix is reusing the parent
     * Page's own access_token instead of discarding it.
     */
    public function test_list_pages_gives_the_linked_instagram_business_account_the_parent_pages_access_token(): void
    {
        Http::fake([
            'graph.facebook.com/me/accounts*' => Http::response([
                'data' => [
                    [
                        'id' => 'page-1',
                        'name' => 'College of Nursing',
                        'access_token' => 'page-token-1',
                        'tasks' => ['CREATE_CONTENT'],
                        'instagram_business_account' => [
                            'id' => 'ig-account-1',
                            'username' => 'college_of_nursing',
                            'profile_picture_url' => 'https://example.com/ig.jpg',
                        ],
                    ],
                ],
            ], 200),
        ]);

        config()->set('services.facebook.graph_url', 'https://graph.facebook.com');

        $provider = new FacebookOAuthProvider(Http::getFacadeRoot());
        $pages = $provider->listPages('user-access-token', []);

        $this->assertCount(2, $pages);
        $instagramEntry = $pages[1];

        $this->assertSame('instagram_business', $instagramEntry['kind']);
        $this->assertSame('ig-account-1', $instagramEntry['page_id']);
        $this->assertSame('college_of_nursing', $instagramEntry['name']);
        $this->assertSame('page-token-1', $instagramEntry['access_token']);
        $this->assertTrue($instagramEntry['can_publish']);
        $this->assertSame('page-1', $instagramEntry['metadata']['parent_page_id']);
    }
}
