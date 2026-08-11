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
     * FacebookOAuthProvider::publishPost() (since 2026-08-11) genuinely
     * uploads images (single via /photos, multiple via unpublished
     * /photos + /feed attached_media) and a single video (via /videos) —
     * see that class for the real Graph API calls. What it still can't do,
     * and what this mirrors so the account gets a real message before
     * attempt/job creation instead of discovering it from a failed
     * publish: a document attachment (not a native Page-post media type),
     * more than one video, or mixing video with images in the same post.
     * Telegram has no such restriction (see TelegramProvider). Runs in
     * every environment (not just production) since this is a real
     * capability gap, not a beta-availability policy.
     *
     * @param  iterable<SocialPage>  $pages
     */
    public function assertMediaSupportedByTargets(Post $post, iterable $pages): void
    {
        $attachments = $post->mediaAttachments()->get(['type']);
        if ($attachments->isEmpty()) {
            return;
        }

        $hasImage = $attachments->contains(fn ($attachment) => $attachment->type === 'image');
        $videoCount = $attachments->where('type', 'video')->count();
        $hasUnsupportedType = $attachments->contains(
            fn ($attachment) => ! in_array($attachment->type, ['image', 'video'], true),
        );
        $isSupportedCombination = ! $hasUnsupportedType && $videoCount <= 1 && ! ($videoCount === 1 && $hasImage);

        if ($isSupportedCombination) {
            return;
        }

        foreach ($pages as $page) {
            $page->loadMissing('socialAccount');
            $account = $page->socialAccount;

            if ($account !== null && strtolower($account->provider) === 'facebook') {
                throw ValidationException::withMessages([
                    'media_attachments' => [__('publishing.facebook_media_not_supported')],
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
            'social_page_ids' => [__('publishing.closed_beta_provider_restricted')],
        ]);
    }
}
