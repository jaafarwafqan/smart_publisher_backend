<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 2026-08-12: public, unauthenticated HTML pages for Meta App Dashboard's
 * Privacy Policy / Terms of Service / User Data Deletion URL fields — Meta
 * needs real crawlable HTTPS URLs, not something bundled inside the mobile
 * app (docs/legal/*.md, which stay the internal source of truth these pages
 * are filled in from). No auth required — these must be reachable before a
 * user ever signs in.
 */
class LegalPagesTest extends TestCase
{
    public function test_privacy_policy_is_publicly_reachable_and_names_the_real_operator(): void
    {
        $response = $this->get('/legal/privacy-policy');

        $response->assertOk();
        $response->assertSee('University of Kufa', false);
        $response->assertSee('jaafarw.alkuby@uokufa.edu.iq', false);
    }

    public function test_terms_of_service_is_publicly_reachable(): void
    {
        $response = $this->get('/legal/terms-of-service');

        $response->assertOk();
        $response->assertSee('University of Kufa', false);
        $response->assertSee('Republic of Iraq', false);
    }

    /**
     * Must describe the real in-app path (Settings -> Delete my account,
     * matching settingsDataDeletionTitle in app_en.arb) and the real
     * retention period the privacy policy also commits to — these two pages
     * must never silently drift apart.
     */
    public function test_data_deletion_instructions_describe_the_real_in_app_path_and_retention(): void
    {
        $response = $this->get('/legal/data-deletion');

        $response->assertOk();
        $response->assertSee('Delete my account', false);
        $response->assertSee('/api/v1/account/data-deletion-requests', false);
        $response->assertSee('30 days', false);
    }

    public function test_privacy_policy_and_data_deletion_page_agree_on_the_retention_period(): void
    {
        $privacy = $this->get('/legal/privacy-policy')->getContent();
        $deletion = $this->get('/legal/data-deletion')->getContent();

        $this->assertStringContainsString('30 days', (string) $privacy);
        $this->assertStringContainsString('30 days', (string) $deletion);
    }
}
