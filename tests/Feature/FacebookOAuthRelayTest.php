<?php

namespace Tests\Feature;

use Tests\TestCase;

class FacebookOAuthRelayTest extends TestCase
{
    public function test_it_relays_a_facebook_code_and_state_to_the_mobile_app(): void
    {
        $response = $this->get('/oauth/facebook/callback?code=auth-code&state=csrf-state');

        $response->assertRedirect(
            'smartpublisher://oauth/callback?code=auth-code&state=csrf-state'
        );
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_it_relays_an_oauth_denial_without_unrelated_query_parameters(): void
    {
        $response = $this->get(
            '/oauth/facebook/callback?error=access_denied&error_description=Denied&unexpected=value'
        );

        $response->assertRedirect(
            'smartpublisher://oauth/callback?error=access_denied&error_description=Denied'
        );
    }
}
