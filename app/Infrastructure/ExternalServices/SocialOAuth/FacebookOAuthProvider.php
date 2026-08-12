<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FacebookOAuthProvider implements SocialOAuthProviderContract
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        $authorizeUrl = (string) Arr::get($context, 'provider_config.authorize_url', '');
        $query = [
            'client_id' => (string) Arr::get($context, 'provider_config.client_id', ''),
            'redirect_uri' => (string) Arr::get($context, 'redirect_uri', ''),
            'state' => (string) Arr::get($context, 'state', ''),
            'response_type' => 'code',
            'scope' => implode(',', Arr::get($context, 'scopes', Arr::get($context, 'provider_config.default_scopes', []))),
        ];

        return $authorizeUrl.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        $providerConfig = Arr::get($context, 'provider_config', []);
        $response = $this->http->asForm()->post((string) Arr::get($providerConfig, 'token_url'), [
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'client_secret' => (string) Arr::get($providerConfig, 'client_secret'),
            'redirect_uri' => (string) Arr::get($context, 'redirect_uri', ''),
            'code' => $authorizationCode,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token exchange failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');

        if ($accessToken === '') {
            throw new RuntimeException('Facebook token exchange returned empty access token.');
        }

        $profileResponse = $this->http->get('https://graph.facebook.com/me', [
            'fields' => 'id,name',
            'access_token' => $accessToken,
        ]);

        if ($profileResponse->failed()) {
            throw new RuntimeException('Facebook profile fetch failed: '.$profileResponse->body());
        }

        $profile = $profileResponse->json();
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        return [
            'provider_account_id' => (string) Arr::get($profile, 'id'),
            'account_name' => (string) Arr::get($profile, 'name'),
            'account_username' => null,
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', ''),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'scopes' => Arr::get($context, 'scopes', Arr::get($providerConfig, 'default_scopes', [])),
            'metadata' => [
                'provider' => 'facebook',
                'token_type' => Arr::get($tokenData, 'token_type'),
                'raw_profile' => $profile,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        $providerConfig = Arr::get($context, 'provider_config', []);
        $response = $this->http->asForm()->post((string) Arr::get($providerConfig, 'token_url'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) Arr::get($providerConfig, 'client_id'),
            'client_secret' => (string) Arr::get($providerConfig, 'client_secret'),
            'fb_exchange_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token refresh failed: '.$response->body());
        }

        $tokenData = $response->json();
        $accessToken = (string) Arr::get($tokenData, 'access_token', '');
        $expiresIn = (int) Arr::get($tokenData, 'expires_in', (int) config('social.default_token_ttl_minutes', 60) * 60);

        if ($accessToken === '') {
            throw new RuntimeException('Facebook refresh returned empty access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => (string) Arr::get($tokenData, 'refresh_token', $refreshToken),
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
            'metadata' => [
                'provider' => 'facebook',
                'refreshed' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        // Reproduced live: publishing content ON a Page (creating a photo,
        // video, or feed post there) requires that Page's own scoped
        // token — the account-level user token this method receives by
        // default gets a generic, misleading Graph API rejection
        // ("(#200) publish_actions...deprecated") instead of a clear
        // permissions error. page_access_token (SocialPage.access_token,
        // captured in listPages() below and persisted by
        // SocialPageSyncService) is preferred when present; falling back to
        // the user token keeps this working for any page synced before
        // this fix ran once (until its next sync populates the real one).
        $token = (string) Arr::get($context, 'page_access_token', '') ?: $accessToken;
        $providerAccountId = (string) Arr::get($context, 'provider_account_id', 'me');
        $text = (string) Arr::get($context, 'content', Arr::get($context, 'title', ''));
        $attachments = (array) Arr::get($context, 'attachments', []);

        if (empty($attachments)) {
            return $this->publishTextOnly($token, $providerAccountId, $text);
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

        // ClosedBetaPublishingGate::assertMediaSupportedByTargets is the
        // real, always-runs guard against reaching this branch with a
        // combination that isn't actually implemented — this is a second,
        // defensive check inside the provider itself, in case that call
        // site is ever skipped (a legacy queue payload, a future direct
        // caller). Refusing outright beats silently dropping media or
        // guessing at an unverified Graph API combination.
        if ($unsupported !== [] || $videos !== [] && $images !== [] || count($videos) > 1) {
            throw new ProviderPublishException(
                'Facebook publishing supports one or more images, or exactly one video, per post — not this combination.',
                httpStatus: 422,
            );
        }

        if (count($videos) === 1) {
            return $this->publishVideo($token, $providerAccountId, $videos[0], $text);
        }

        if (count($images) === 1) {
            return $this->publishSinglePhoto($token, $providerAccountId, $images[0], $text);
        }

        return $this->publishMultiplePhotos($token, $providerAccountId, $images, $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishTextOnly(string $accessToken, string $providerAccountId, string $text): array
    {
        $graphUrl = $this->graphUrl();

        $response = $this->http->asForm()->post($graphUrl.'/'.$providerAccountId.'/feed', [
            'message' => $text,
            'access_token' => $accessToken,
        ]);

        $data = $this->assertSucceeded($response, 'Facebook publish failed');

        return $this->publishedResult((string) Arr::get($data, 'id', ''), $data);
    }

    /**
     * A single photo + caption is one call: POST /{page}/photos with the
     * image as `source` (streamed, not read into memory — same reasoning as
     * TelegramProvider::sendAttachment for a large video) and `published`
     * left at its default `true` creates a real Page post in one round
     * trip, not just an unattached photo. The response's `post_id` (not
     * `id`, which is the photo's own id) is the actual Feed post id.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private function publishSinglePhoto(
        string $accessToken,
        string $providerAccountId,
        array $attachment,
        string $caption,
    ): array {
        $graphUrl = $this->graphUrl();

        $response = $this->http
            ->attach('source', $this->streamAttachment($attachment), $this->attachmentFileName($attachment))
            ->post($graphUrl.'/'.$providerAccountId.'/photos', [
                'caption' => $caption,
                'access_token' => $accessToken,
            ]);

        $data = $this->assertSucceeded($response, 'Facebook photo publish failed');
        $postId = Arr::get($data, 'post_id', Arr::get($data, 'id', ''));

        return $this->publishedResult((string) $postId, $data);
    }

    /**
     * Multiple photos in one post is a two-step Graph API dance: upload
     * each photo `published=false` (an unattached photo, not yet a post —
     * `id` here is the photo id) to collect their ids, then create one real
     * Feed post referencing all of them via `attached_media`.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<string, mixed>
     */
    private function publishMultiplePhotos(
        string $accessToken,
        string $providerAccountId,
        array $attachments,
        string $caption,
    ): array {
        $graphUrl = $this->graphUrl();
        $photoIds = [];

        foreach ($attachments as $attachment) {
            $response = $this->http
                ->attach('source', $this->streamAttachment($attachment), $this->attachmentFileName($attachment))
                ->post($graphUrl.'/'.$providerAccountId.'/photos', [
                    'published' => 'false',
                    'access_token' => $accessToken,
                ]);

            $data = $this->assertSucceeded($response, 'Facebook photo upload failed');
            $photoId = (string) Arr::get($data, 'id', '');
            if ($photoId === '') {
                throw new ProviderPublishException(
                    'Facebook photo upload returned no photo id: '.$response->body(),
                    httpStatus: $response->status(),
                );
            }
            $photoIds[] = $photoId;
        }

        $attachedMedia = array_map(
            fn (string $photoId): string => json_encode(['media_fbid' => $photoId], JSON_THROW_ON_ERROR),
            $photoIds,
        );

        $feedPayload = ['message' => $caption, 'access_token' => $accessToken];
        foreach ($attachedMedia as $index => $encoded) {
            $feedPayload["attached_media[{$index}]"] = $encoded;
        }

        $response = $this->http->asForm()->post($graphUrl.'/'.$providerAccountId.'/feed', $feedPayload);
        $data = $this->assertSucceeded($response, 'Facebook multi-photo publish failed');

        return $this->publishedResult((string) Arr::get($data, 'id', ''), $data);
    }

    /**
     * Direct (non-resumable) upload via `source` — Meta's own docs
     * document this as valid for smaller files; a large video would need
     * the separate chunked/resumable upload API, not implemented here.
     * `description` is the video's caption-equivalent field.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private function publishVideo(
        string $accessToken,
        string $providerAccountId,
        array $attachment,
        string $caption,
    ): array {
        $graphUrl = $this->graphUrl();

        $response = $this->http
            ->attach('source', $this->streamAttachment($attachment), $this->attachmentFileName($attachment))
            ->post($graphUrl.'/'.$providerAccountId.'/videos', [
                'description' => $caption,
                'access_token' => $accessToken,
            ]);

        $data = $this->assertSucceeded($response, 'Facebook video publish failed');

        return $this->publishedResult((string) Arr::get($data, 'id', ''), $data);
    }

    /**
     * @param  array<string, mixed>  $attachment
     * @return resource
     */
    private function streamAttachment(array $attachment)
    {
        $disk = (string) Arr::get($attachment, 'disk', 'public');
        $path = (string) Arr::get($attachment, 'path', '');

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("Attachment file not found on disk: {$path}");
        }

        // A stream, not Storage::get()'s full-file string — a large video
        // read via get() would sit entirely in the queue worker's memory
        // before a single byte reaches Facebook.
        $stream = Storage::disk($disk)->readStream($path);
        if ($stream === null) {
            throw new RuntimeException("Attachment file could not be opened for streaming: {$path}");
        }

        return $stream;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function attachmentFileName(array $attachment): string
    {
        return (string) Arr::get(
            $attachment,
            'original_name',
            basename((string) Arr::get($attachment, 'path', 'file')),
        );
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
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function publishedResult(string $providerPostId, array $raw): array
    {
        return [
            'provider_post_id' => $providerPostId,
            'status' => 'published',
            'published_at' => Carbon::now()->toIso8601String(),
            'raw_response' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $response = $this->http->get($graphUrl.'/me/accounts', [
            // instagram_business_account is requested here (not via a separate
            // Instagram OAuth flow) because Instagram Business accounts have no
            // independent OAuth — they only exist linked to a Facebook Page.
            // access_token here IS the page-scoped token (2026-08-12: was
            // deliberately not requested before, on the assumption the
            // account-level user token was sufficient for publishing — a
            // live publish attempt proved that wrong; Meta requires this
            // exact token to create content on a Page). Persisted encrypted
            // on SocialPage.access_token by SocialPageSyncService, used by
            // publishPost() above via context['page_access_token'].
            'fields' => 'id,name,picture,tasks,access_token,instagram_business_account{id,username,profile_picture_url}',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook pages fetch failed: '.$response->body());
        }

        $pages = (array) Arr::get($response->json(), 'data', []);

        $results = [];

        foreach ($pages as $page) {
            $tasks = (array) Arr::get($page, 'tasks', []);
            $canPublish = in_array('CREATE_CONTENT', $tasks, true);

            $results[] = [
                'page_id' => (string) Arr::get($page, 'id'),
                'name' => Arr::get($page, 'name'),
                'picture_url' => Arr::get($page, 'picture.data.url'),
                'access_token' => Arr::get($page, 'access_token'),
                'can_publish' => $canPublish,
                'metadata' => [
                    'tasks' => $tasks,
                ],
            ];

            $instagramAccount = Arr::get($page, 'instagram_business_account');
            if (is_array($instagramAccount) && ! empty($instagramAccount['id'])) {
                $results[] = [
                    'kind' => 'instagram_business',
                    'page_id' => (string) $instagramAccount['id'],
                    'name' => Arr::get($instagramAccount, 'username'),
                    'picture_url' => Arr::get($instagramAccount, 'profile_picture_url'),
                    // Instagram Business publishing goes through a
                    // different Graph API surface than a Facebook Page's
                    // feed/photos/videos — out of scope here (not
                    // implemented, unlike the parent Page above), so no
                    // token to capture for it yet.
                    'access_token' => null,
                    'can_publish' => $canPublish,
                    'metadata' => [
                        'parent_page_id' => (string) Arr::get($page, 'id'),
                    ],
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $response = $this->http->get($graphUrl.'/me', [
            'fields' => 'id',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            $error = (string) Arr::get($response->json() ?? [], 'error.message', 'Facebook rejected this access token.');

            return ['available' => true, 'healthy' => false, 'message' => $error];
        }

        return ['available' => true, 'healthy' => true, 'message' => 'Connection is healthy.'];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array
    {
        $clientId = (string) Arr::get($providerConfig, 'client_id', '');
        $clientSecret = (string) Arr::get($providerConfig, 'client_secret', '');
        $tokenUrl = (string) Arr::get($providerConfig, 'token_url', '');

        if ($clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => 'Client ID and Client Secret are required.'];
        }

        if ($tokenUrl === '') {
            return ['success' => false, 'message' => 'Token URL is not configured for Facebook.'];
        }

        // Facebook's token endpoint mints an app-only access token when asked
        // for grant_type=client_credentials, which only succeeds if the
        // Client ID/Secret pair genuinely matches a real Facebook app.
        $response = $this->http->get($tokenUrl, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            $error = (string) Arr::get($response->json() ?? [], 'error.message', 'Facebook rejected these credentials.');

            return ['success' => false, 'message' => $error];
        }

        return ['success' => true, 'message' => 'Facebook credentials verified successfully.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string, metrics?: array{impressions:int,reach:int,clicks:int,reactions:int,shares:int,comments:int}}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        $graphUrl = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com'), '/');

        $insightsResponse = $this->http->get($graphUrl.'/'.$providerPostId.'/insights', [
            'metric' => 'post_impressions,post_impressions_unique,post_clicks,post_reactions_by_type_total',
            'access_token' => $accessToken,
        ]);

        if ($insightsResponse->failed()) {
            $error = (string) Arr::get($insightsResponse->json() ?? [], 'error.message', 'Facebook insights fetch failed.');

            return ['available' => false, 'message' => $error];
        }

        $values = [];
        foreach ((array) Arr::get($insightsResponse->json(), 'data', []) as $metric) {
            $values[(string) Arr::get($metric, 'name')] = Arr::get($metric, 'values.0.value');
        }

        $reactionsByType = $values['post_reactions_by_type_total'] ?? [];
        $reactions = is_array($reactionsByType) ? array_sum($reactionsByType) : 0;

        $fieldsResponse = $this->http->get($graphUrl.'/'.$providerPostId, [
            'fields' => 'shares,comments.summary(true)',
            'access_token' => $accessToken,
        ]);

        $shares = 0;
        $comments = 0;
        if ($fieldsResponse->ok()) {
            $shares = (int) Arr::get($fieldsResponse->json(), 'shares.count', 0);
            $comments = (int) Arr::get($fieldsResponse->json(), 'comments.summary.total_count', 0);
        }

        return [
            'available' => true,
            'message' => 'Metrics fetched successfully.',
            'metrics' => [
                'impressions' => (int) ($values['post_impressions'] ?? 0),
                'reach' => (int) ($values['post_impressions_unique'] ?? 0),
                'clicks' => (int) ($values['post_clicks'] ?? 0),
                'reactions' => (int) $reactions,
                'shares' => $shares,
                'comments' => $comments,
            ],
        ];
    }
}
