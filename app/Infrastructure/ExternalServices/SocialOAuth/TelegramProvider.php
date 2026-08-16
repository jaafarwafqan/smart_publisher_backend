<?php

namespace App\Infrastructure\ExternalServices\SocialOAuth;

use App\Exceptions\Publishing\ProviderPublishException;
use App\Infrastructure\ExternalServices\Contracts\SocialOAuthProviderContract;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TelegramProvider implements SocialOAuthProviderContract
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function buildAuthorizeUrl(array $context): string
    {
        return '';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function exchangeCodeForToken(string $authorizationCode, array $context): array
    {
        throw new RuntimeException('Telegram does not use OAuth code exchange.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken, array $context): array
    {
        throw new RuntimeException('Telegram bot tokens do not expire or refresh.');
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyBotToken(string $botToken): array
    {
        $response = $this->http->get("https://api.telegram.org/bot{$botToken}/getMe");

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException('Invalid Telegram bot token.');
        }

        return (array) $response->json('result');
    }

    /**
     * Phase 3 (webhook receiver, 2026-08-16): subscribes this bot to
     * Telegram's push delivery instead of relying only on the periodic
     * `oauth-providers:health-check` poll. Best-effort by design — Telegram
     * refuses a non-HTTPS or non-public URL (e.g. a local dev APP_URL), and
     * that must never block the underlying bot-token connect this is called
     * from; callers decide whether to log/report a failure, not fail the
     * connect. secret_token is echoed back by Telegram on every delivery as
     * the X-Telegram-Bot-Api-Secret-Token header — the receiver's proof this
     * request really came from Telegram for this exact bot.
     */
    public function registerWebhook(string $botToken, string $url, string $secretToken): bool
    {
        $response = $this->http->asForm()->post("https://api.telegram.org/bot{$botToken}/setWebhook", [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => json_encode(['channel_post', 'edited_channel_post', 'my_chat_member']),
        ]);

        return $response->ok() && (bool) $response->json('ok');
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyChat(string $botToken, string $chatIdentifier): array
    {
        $chatResponse = $this->http->get("https://api.telegram.org/bot{$botToken}/getChat", [
            'chat_id' => $chatIdentifier,
        ]);

        if ($chatResponse->failed() || ! $chatResponse->json('ok')) {
            throw new RuntimeException('Telegram channel not found or the bot lacks access.');
        }

        $chat = (array) $chatResponse->json('result');
        $bot = $this->verifyBotToken($botToken);

        $memberResponse = $this->http->get("https://api.telegram.org/bot{$botToken}/getChatMember", [
            'chat_id' => $chatIdentifier,
            'user_id' => Arr::get($bot, 'id'),
        ]);

        if ($memberResponse->ok() && $memberResponse->json('ok')) {
            $status = $memberResponse->json('result.status');
            $canPost = in_array($status, ['administrator', 'creator'], true)
                && ($memberResponse->json('result.can_post_messages') ?? true);
        } else {
            // Telegram can refuse getChatMember for a channel's own bot with
            // "member list is inaccessible" depending on the channel's privacy
            // settings, even though the bot really is an admin. getChat already
            // succeeding proves the bot has access, so trust that instead of
            // blocking on a check Telegram won't answer for this channel.
            $canPost = true;
        }

        $memberCount = null;
        $countResponse = $this->http->get("https://api.telegram.org/bot{$botToken}/getChatMemberCount", [
            'chat_id' => $chatIdentifier,
        ]);
        if ($countResponse->ok()) {
            $memberCount = $countResponse->json('result');
        }

        return [
            'page_id' => (string) Arr::get($chat, 'id'),
            'name' => Arr::get($chat, 'title', Arr::get($chat, 'username', $chatIdentifier)),
            'username' => Arr::get($chat, 'username') ? '@'.Arr::get($chat, 'username') : null,
            'can_publish' => $canPost,
            'metadata' => [
                'type' => Arr::get($chat, 'type'),
                'member_count' => $memberCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $accessToken, array $context): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function publishPost(string $accessToken, array $context): array
    {
        $chatId = (string) Arr::get($context, 'page_id', Arr::get($context, 'provider_account_id', ''));
        $text = (string) Arr::get($context, 'content', Arr::get($context, 'title', ''));
        $attachments = (array) Arr::get($context, 'attachments', []);

        if (empty($attachments)) {
            $result = $this->sendMessage($accessToken, $chatId, $text);

            return [
                'provider_post_id' => (string) Arr::get($result, 'message_id', ''),
                'status' => 'published',
                'published_at' => Carbon::now()->toIso8601String(),
                'raw_response' => $result,
            ];
        }

        $results = [];
        $captionUsed = false;

        foreach ($attachments as $attachment) {
            $result = $this->sendAttachment($accessToken, $chatId, $attachment, $captionUsed ? null : $text);
            $captionUsed = true;
            $results[] = $result;
        }

        $first = $results[0];

        return [
            'provider_post_id' => (string) Arr::get($first, 'message_id', ''),
            'status' => 'published',
            'published_at' => Carbon::now()->toIso8601String(),
            'raw_response' => ['messages' => $results],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessage(string $accessToken, string $chatId, string $text): array
    {
        $response = $this->http->asForm()->post("https://api.telegram.org/bot{$accessToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if ($response->failed() || ! $response->json('ok')) {
            $retryAfter = $response->json('parameters.retry_after') ?? $response->header('Retry-After');

            throw new ProviderPublishException(
                'Telegram sendMessage failed: '.$response->body(),
                httpStatus: $response->status(),
                retryAfterSeconds: $this->retryAfterSeconds($retryAfter),
                responseBody: $response->body(),
            );
        }

        return (array) $response->json('result');
    }

    /**
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private function sendAttachment(string $accessToken, string $chatId, array $attachment, ?string $caption): array
    {
        $disk = (string) Arr::get($attachment, 'disk', 'public');
        $path = (string) Arr::get($attachment, 'path', '');
        $type = (string) Arr::get($attachment, 'type', 'document');
        $fileName = (string) Arr::get($attachment, 'original_name', basename($path));
        $method = $type === 'image' ? 'sendPhoto' : 'sendDocument';
        $field = $type === 'image' ? 'photo' : 'document';

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("Attachment file not found on disk: {$path}");
        }

        // A stream, not Storage::get()'s full-file string — a 500MB video
        // read via get() would sit entirely in the queue worker's memory
        // before a single byte reaches Telegram. readStream() lets Guzzle's
        // multipart body read (and close, once sent) it in chunks instead.
        $stream = Storage::disk($disk)->readStream($path);
        if ($stream === null) {
            throw new RuntimeException("Attachment file could not be opened for streaming: {$path}");
        }

        $request = $this->http->attach($field, $stream, $fileName);
        $payload = ['chat_id' => $chatId];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        $response = $request->post("https://api.telegram.org/bot{$accessToken}/{$method}", $payload);

        if ($response->failed() || ! $response->json('ok')) {
            $retryAfter = $response->json('parameters.retry_after') ?? $response->header('Retry-After');

            throw new ProviderPublishException(
                "Telegram {$method} failed: ".$response->body(),
                httpStatus: $response->status(),
                retryAfterSeconds: $this->retryAfterSeconds($retryAfter),
                responseBody: $response->body(),
            );
        }

        return (array) $response->json('result');
    }

    private function retryAfterSeconds(mixed $retryAfter): ?int
    {
        if (is_int($retryAfter)) {
            return $retryAfter;
        }

        if (! is_string($retryAfter) || $retryAfter === '' || ! ctype_digit($retryAfter)) {
            return null;
        }

        return (int) $retryAfter;
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $providerConfig): array
    {
        return [
            'success' => false,
            'message' => 'Telegram has no application-level credentials to verify; connect a bot token per channel instead.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, healthy: bool, message: string}
     */
    public function checkAccountHealth(string $accessToken, array $context): array
    {
        try {
            $this->verifyBotToken($accessToken);
        } catch (RuntimeException $e) {
            return ['available' => true, 'healthy' => false, 'message' => $e->getMessage()];
        }

        return ['available' => true, 'healthy' => true, 'message' => 'Connection is healthy.'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{available: bool, message: string}
     */
    public function fetchPostMetrics(string $accessToken, string $providerPostId, array $context): array
    {
        return [
            'available' => false,
            'message' => 'Metrics are not available for this provider yet.',
        ];
    }
}
