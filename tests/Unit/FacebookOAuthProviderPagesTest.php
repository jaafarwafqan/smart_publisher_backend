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
}
