<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use App\Support\Media\PublicMediaUrlResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An Instagram Business connection is discovered as a child of a connected
 * Facebook Page (FacebookOAuthProvider::listPages() surfaces it via the
 * Graph API's instagram_business_account field) — there's no separate
 * Instagram OAuth handshake, so the OAuth methods delegate to
 * FacebookOAuthProvider exactly like WhatsAppProvider does. What's genuinely
 * different is publishing: Instagram has its own Content Publishing API
 * (two-step container-then-publish, not the Page's /feed or /photos), and
 * requires a real HTTP-fetchable media URL rather than a multipart upload —
 * see publishPost() below.
 */
class InstagramProvider implements SocialOAuthProviderContract
{
    /**
     * How many times to poll a video/reel container's processing status
     * before giving up. Container processing is genuinely asynchronous on
     * Meta's side; a bounded loop with a short sleep between polls is the
     * documented pattern, not a bug — but it must terminate rather than
     * hang a queue worker forever on a stuck container.
     */
    private const MAX_STATUS_POLLS = 10;

    public function __construct(
        private readonly FacebookOAuthProvider $facebookProvider,
        private readonly HttpFactory $http,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        return $this->facebookProvider->buildAuthorizeUrl($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        return $this->facebookProvider->exchangeCodeForToken($authorizationCode, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        return $this->facebookProvider->refreshAccessToken($refreshToken, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        // Same reasoning as FacebookOAuthProvider::publishPost(): the
        // account-level user token can list the linked IG Business Account,
        // but creating content on it requires the Page's own scoped token —
        // now real (see FacebookOAuthProvider::listPages()'s 2026-08 fix),
        // where before it was deliberately null and Instagram publishing
        // wasn't implemented at all.
        $token = (string) Arr::get($context, 'page_access_token', '') ?: $accessToken;
        $igUserId = (string) Arr::get($context, 'provider_account_id', '');
        $caption = (string) Arr::get($context, 'content', Arr::get($context, 'title', ''));
        $attachments = (array) Arr::get($context, 'attachments', []);

        if (empty($attachments)) {
            // Unlike Facebook/Telegram, Instagram has no text-only feed
            // post at all — every real post requires at least one image or
            // video. ClosedBetaPublishingGate::assertMediaSupportedByTargets()
            // is the real, always-runs guard against reaching this branch
            // (see that class); this is a second, defensive check in case
            // that call site is ever skipped.
            throw new ProviderPublishException(
                'Instagram requires at least one image or video attachment — there is no text-only post.',
                httpStatus: 422,
            );
        }

        $images = array_values(array_filter(
            $attachments,
            fn (array $attachment): bool => (string) Arr::get($attachment, 'type') === 'image',
        ));
        $videos = array_values(array_filter(
            $attachments,
            fn (array $attachment): bool => (string) Arr::get($attachment, 'type') === 'video',
        ));
        $unsupported = array_values(array_filter(
            $attachments,
            fn (array $attachment): bool => ! in_array((string) Arr::get($attachment, 'type'), ['image', 'video'], true),
        ));

        if ($unsupported !== [] || count($images) + count($videos) > 10) {
            throw new ProviderPublishException(
                'Instagram publishing supports up to 10 images/videos (as a carousel), or a single image or video — not this combination.',
                httpStatus: 422,
            );
        }

        if (count($attachments) === 1) {
            $containerId = $videos !== []
                ? $this->createVideoContainer($token, $igUserId, $videos[0], $caption)
                : $this->createImageContainer($token, $igUserId, $images[0], $caption, false);

            return $this->publishContainer($token, $igUserId, $containerId);
        }

        $childIds = [];
        foreach ($images as $attachment) {
            $childIds[] = $this->createImageContainer($token, $igUserId, $attachment, null, true);
        }
        foreach ($videos as $attachment) {
            $childIds[] = $this->createVideoContainer($token, $igUserId, $attachment, null, true);
        }

        $carouselContainerId = $this->createCarouselContainer($token, $igUserId, $childIds, $caption);

        return $this->publishContainer($token, $igUserId, $carouselContainerId);
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function createImageContainer(
        string $accessToken,
        string $igUserId,
        array $attachment,
        ?string $caption,
        bool $isCarouselItem,
    ): string {
        $payload = array_filter([
            'image_url' => $this->mediaUrl($attachment),
            'caption' => $caption,
            'is_carousel_item' => $isCarouselItem ? 'true' : null,
            'access_token' => $accessToken,
        ], fn ($value) => $value !== null);

        $response = $this->http->asForm()->post($this->graphUrl().'/'.$igUserId.'/media', $payload);
        $data = $this->assertSucceeded($response, 'Instagram image container creation failed');

        return $this->requireContainerId($data, $response);
    }

    /**
     * Video/reel containers process asynchronously on Meta's side —
     * creating the container just queues it; publishContainer() would fail
     * with an "not ready" error if called before it reaches FINISHED, so
     * this polls status_code first. See MAX_STATUS_POLLS's docblock for why
     * the loop is bounded.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function createVideoContainer(
        string $accessToken,
        string $igUserId,
        array $attachment,
        ?string $caption,
        bool $isCarouselItem = false,
    ): string {
        $payload = array_filter([
            'video_url' => $this->mediaUrl($attachment),
            'media_type' => $isCarouselItem ? 'VIDEO' : 'REELS',
            'caption' => $caption,
            'is_carousel_item' => $isCarouselItem ? 'true' : null,
            'access_token' => $accessToken,
        ], fn ($value) => $value !== null);

        $response = $this->http->asForm()->post($this->graphUrl().'/'.$igUserId.'/media', $payload);
        $data = $this->assertSucceeded($response, 'Instagram video container creation failed');
        $containerId = $this->requireContainerId($data, $response);

        $this->waitForContainerReady($accessToken, $containerId);

        return $containerId;
    }

    private function waitForContainerReady(string $accessToken, string $containerId): void
    {
        for ($attempt = 0; $attempt < self::MAX_STATUS_POLLS; $attempt++) {
            $response = $this->http->get($this->graphUrl().'/'.$containerId, [
                'fields' => 'status_code',
                'access_token' => $accessToken,
            ]);

            $status = (string) Arr::get($response->json() ?? [], 'status_code', '');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new ProviderPublishException(
                    'Instagram video processing failed: '.$response->body(),
                    httpStatus: 422,
                    responseBody: $response->body(),
                );
            }

            if ($attempt < self::MAX_STATUS_POLLS - 1) {
                // Configurable (not a bare constant) so tests can drive this
                // to 0 — a real poll interval would otherwise make a single
                // video-publish test take tens of seconds for no reason.
                usleep((int) config('services.instagram.status_poll_delay_seconds', 3) * 1_000_000);
            }
        }

        throw new ProviderPublishException(
            'Instagram video did not finish processing in time — it may still complete; check the account before retrying.',
            httpStatus: 503,
            retryAfterSeconds: 30,
        );
    }

    /**
     * @param  list<string>  $childIds
     */
    private function createCarouselContainer(string $accessToken, string $igUserId, array $childIds, string $caption): string
    {
        $response = $this->http->asForm()->post($this->graphUrl().'/'.$igUserId.'/media', [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $caption,
            'access_token' => $accessToken,
        ]);

        $data = $this->assertSucceeded($response, 'Instagram carousel container creation failed');

        return $this->requireContainerId($data, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishContainer(string $accessToken, string $igUserId, string $containerId): array
    {
        $response = $this->http->asForm()->post($this->graphUrl().'/'.$igUserId.'/media_publish', [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        $data = $this->assertSucceeded($response, 'Instagram publish failed');
        $postId = (string) Arr::get($data, 'id', '');

        if ($postId === '') {
            throw new ProviderPublishException(
                'Instagram publish returned no post id: '.$response->body(),
                httpStatus: $response->status(),
            );
        }

        return [
            'provider_post_id' => $postId,
            'status' => 'published',
            'published_at' => Carbon::now()->toIso8601String(),
            'raw_response' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function mediaUrl(array $attachment): string
    {
        $disk = (string) Arr::get($attachment, 'disk', 'public');
        $path = (string) Arr::get($attachment, 'path', '');

        if ($path === '') {
            throw new RuntimeException('Attachment has no stored path.');
        }

        // Instagram's Content Publishing API fetches the media itself over
        // HTTP rather than accepting a multipart upload (unlike Facebook's
        // /photos and /videos, and Telegram's sendPhoto/sendDocument) — it
        // needs a real, signed, fetchable-without-our-auth URL. 15 minutes
        // is generous enough for Meta's servers to fetch it (they do so
        // essentially immediately on container creation) without leaving it
        // valid indefinitely.
        return PublicMediaUrlResolver::resolve($disk, $path, 15);
    }

    private function graphUrl(): string
    {
        return rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSucceeded(Response $response, string $errorPrefix): array
    {
        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new ProviderPublishException(
                $errorPrefix.': '.$response->body(),
                httpStatus: $response->status(),
                retryAfterSeconds: $retryAfter === '' ? null : (int) $retryAfter,
                responseBody: $response->body(),
            );
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireContainerId(array $data, Response $response): string
    {
        $containerId = (string) Arr::get($data, 'id', '');

        if ($containerId === '') {
            throw new ProviderPublishException(
                'Instagram container creation returned no id: '.$response->body(),
                httpStatus: $response->status(),
            );
        }

        return $containerId;
    }

    /**
     * Discovery already happens through FacebookOAuthProvider::listPages()
     * in the same sync call that discovers the parent Page (see the
     * instagram_business_account child entry there) — there's no separate
     * Instagram-only listing endpoint to call.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        return [];
    }

    /**
     * Instagram publishing shares Facebook's app credentials (same Meta
     * app, same OAuth handshake) — there's nothing Instagram-specific to
     * verify at the app-credential level beyond what Facebook's check
     * already covers.
     *
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array
    {
        return $this->facebookProvider->testConnection($providerConfig);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string, metrics?: array{impressions:int,reach:int,clicks:int,reactions:int,shares:int,comments:int}}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        $token = (string) Arr::get($context, 'page_access_token', '') ?: $accessToken;

        $response = $this->http->get($this->graphUrl().'/'.$providerPostId.'/insights', [
            'metric' => 'impressions,reach,likes,comments,shares,saved',
            'access_token' => $token,
        ]);

        if ($response->failed()) {
            $error = (string) Arr::get($response->json() ?? [], 'error.message', 'Instagram insights fetch failed.');

            return ['available' => false, 'message' => $error];
        }

        $values = [];
        foreach ((array) Arr::get($response->json(), 'data', []) as $metric) {
            $values[(string) Arr::get($metric, 'name')] = Arr::get($metric, 'values.0.value', 0);
        }

        return [
            'available' => true,
            'message' => 'Metrics fetched successfully.',
            'metrics' => [
                'impressions' => (int) ($values['impressions'] ?? 0),
                'reach' => (int) ($values['reach'] ?? 0),
                'clicks' => 0,
                'reactions' => (int) ($values['likes'] ?? 0),
                'shares' => (int) ($values['shares'] ?? 0),
                'comments' => (int) ($values['comments'] ?? 0),
            ],
        ];
    }

    /**
     * An Instagram Business Account's access token is the same linked
     * Page's token, so its health is genuinely the same check Facebook's
     * own account health uses.
     *
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        return $this->facebookProvider->checkAccountHealth($accessToken, $context);
    }
}
