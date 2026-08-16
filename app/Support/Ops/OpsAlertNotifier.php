<?php

namespace App\Support\Ops;

use App\Services\ContextLogger;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Phase 4 (observability, 2026-08-16): the real delivery half of
 * OpsSnapshotCommand's threshold-breach alerts. ContextLogger::warning()/
 * error() (already real, already the source of truth for these alerts)
 * stays exactly as it was — this is purely an additional side channel, and
 * a real delivery failure here must never surface as this command's own
 * failure or suppress the log-based alert that already fired.
 *
 * Deliberately fail-safe/opt-in: config('ops.telegram_alert') requires both
 * a bot token AND a chat id before anything is sent — either missing means
 * a silent no-op, not an error. There is no forced dependency on Telegram
 * for this project's operational alerting to be "real"; it's an
 * enhancement the operator opts into by setting two env vars.
 */
class OpsAlertNotifier
{
    public function __construct(private readonly HttpFactory $http) {}

    public function notify(string $message): void
    {
        $botToken = (string) config('ops.telegram_alert.bot_token', '');
        $chatId = (string) config('ops.telegram_alert.chat_id', '');

        if ($botToken === '' || $chatId === '') {
            return;
        }

        try {
            $response = $this->http->asForm()->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                ['chat_id' => $chatId, 'text' => $message],
            );

            if ($response->failed() || ! $response->json('ok')) {
                ContextLogger::warning('ops.alert.telegram_delivery_failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            // A delivery failure (network, invalid token, chat not found)
            // must never break app:ops-snapshot itself — the log-based
            // alert this supplements already fired before this is called.
            ContextLogger::warning('ops.alert.telegram_delivery_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
