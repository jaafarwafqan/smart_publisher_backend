<?php

namespace App\Support\Publishing;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Models\Post;
use App\Models\SocialPage;
use Illuminate\Validation\ValidationException;

/**
 * The trusted release gate for publishable destinations.
 *
 * Discovery may retain development-only destinations outside production, but
 * the closed beta can dispatch only to a Facebook Page or Telegram channel.
 * Keep this check before attempt/job creation; the worker-level provider
 * call is intentionally only a final defence against legacy queue payloads.
 */
final class ClosedBetaPublishingGate
{
    public function __construct(
        private readonly SocialOAuthManager $oauthManager,
    ) {}

    /**
     * @param  iterable<SocialPage>  $pages
     */
    public function assertPagesAllowed(iterable $pages): void
    {
        if (! app()->environment('production')) {
            return;
        }

        foreach ($pages as $page) {
            $this->assertPageAllowed($page);
        }
    }

    public function assertPostTargetsAllowed(Post $post): void
    {
        $this->assertPagesAllowed(
            $post->socialPages()
                ->with('socialAccount')
                ->get(),
        );
    }

    public function assertMediaSupportedByPostTargets(Post $post): void
    {
        $this->assertMediaSupportedByTargets(
            $post,
            $post->socialPages()
                ->with('socialAccount')
                ->get(),
        );
    }

    /**
     * FacebookOAuthProvider::publishPost() only ever sends the post's text
     * to /feed — it silently drops any media attachments rather than
     * uploading them, so a post with photos/video would report a fully
     * successful publish while Facebook only ever received the caption.
     * Telegram genuinely uploads attachments (see TelegramProvider), so
     * this is Facebook-specific, not a blanket "no media anywhere" rule.
     * Runs in every environment (not just production) since this is a real
     * capability gap, not a beta-availability policy.
     *
     * @param  iterable<SocialPage>  $pages
     */
    public function assertMediaSupportedByTargets(Post $post, iterable $pages): void
    {
        if ($post->mediaAttachments()->doesntExist()) {
            return;
        }

        foreach ($pages as $page) {
            $page->loadMissing('socialAccount');
            $account = $page->socialAccount;

            if ($account !== null && strtolower($account->provider) === 'facebook') {
                throw ValidationException::withMessages([
                    'media_attachments' => [
                        'Publishing media attachments to Facebook is not supported yet — remove the attachments or the Facebook target before publishing.',
                    ],
                ]);
            }
        }
    }

    public function assertPageAllowed(SocialPage $page): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $page->loadMissing('socialAccount');
        $account = $page->socialAccount;

        // Account existence is a separate publication precondition. Do not
        // misreport a deleted account as a provider entitlement violation.
        if ($account === null) {
            return;
        }

        $provider = strtolower($account->provider);
        $isAllowedProvider = $this->oauthManager->isClosedBetaProvider($provider);
        $isAllowedKind = match ($provider) {
            'facebook' => $page->kind === 'page',
            'telegram' => $page->kind === 'channel',
            default => false,
        };

        if ($isAllowedProvider && $isAllowedKind) {
            return;
        }

        throw ValidationException::withMessages([
            'social_page_ids' => [
                'Only Facebook Pages and Telegram channels are enabled for the production closed beta.',
            ],
        ]);
    }
}
