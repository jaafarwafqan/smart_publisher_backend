<?php

namespace App\Jobs;

use App\Infrastructure\ExternalServices\Publishing\PostMetricsSyncService;
use App\Models\PlatformWebhookEvent;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Phase 3 (webhook receiver, 2026-08-16). Dispatched on the existing
 * `default` database queue — same "no Redis, no second queue infrastructure"
 * rule as everything else in this project. The controller that received the
 * webhook only verifies the signature/secret and stores the event
 * (idempotent insert, fast, no external calls); every actual side effect —
 * a page-status flip, a metrics re-sync — happens here, off the request
 * path Facebook/Telegram expect a fast 200 from.
 *
 * This job legitimately spans whatever organization the matched social
 * account/page belongs to (unknown at dispatch time from an unauthenticated
 * webhook until the event's own payload is inspected) — same class of
 * cross-tenant lookup as ProcessScheduledPostsJob's own due-post scan, with
 * every actual mutation still wrapped in TenantContext::run() once the
 * organization is resolved. See OrganizationScope's docblock.
 */
class ProcessPlatformWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(PostMetricsSyncService $metricsSync): void
    {
        $event = PlatformWebhookEvent::withoutGlobalScope(OrganizationScope::class)->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            // Nothing to do, or a duplicate delivery whose row was already
            // processed by an earlier attempt — never reprocess.
            return;
        }

        try {
            match ($event->provider) {
                'facebook' => $this->processFacebook($event, $metricsSync),
                'telegram' => $this->processTelegram($event),
                default => null,
            };

            $event->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (Throwable $e) {
            $event->update(['processing_error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Meta's Page `feed` webhook payload does not carry aggregate metrics
     * itself (a `reaction`/`comment`/`share` change is a delta notification,
     * not a running total) — the honest, correct thing to do with it is
     * trigger an immediate real re-sync via the same
     * PostMetricsSyncService/Graph Insights call the hourly `post-metrics:sync`
     * command already uses, not to guess at incrementing a counter from an
     * ambiguous payload shape. This just moves that real sync earlier than
     * the next scheduled tick for the specific post that changed.
     */
    private function processFacebook(PlatformWebhookEvent $event, PostMetricsSyncService $metricsSync): void
    {
        $page = $this->resolveSocialPage($event);
        if ($page === null) {
            return;
        }

        $providerPostId = (string) Arr::get($event->payload, 'value.post_id', '');

        app(TenantContext::class)->run((int) $page->organization_id, function () use ($page, $providerPostId, $metricsSync): void {
            $attempts = PostPublicationAttempt::query()
                ->where('social_page_id', $page->id)
                ->where('status', 'success')
                ->orderByDesc('processed_at')
                ->limit(20)
                ->get();

            $target = $providerPostId !== ''
                ? $attempts->first(fn (PostPublicationAttempt $a): bool => str_contains((string) $a->provider_response, $providerPostId))
                : null;

            // Fall back to the page's most recent successful attempt when the
            // change payload didn't carry a post_id we can match exactly
            // (true for some Facebook change fields) — still a real,
            // targeted re-sync, just at page granularity instead of post
            // granularity.
            $target ??= $attempts->first();

            if ($target !== null) {
                $metricsSync->syncAttempt($target);
            }
        });
    }

    /**
     * Telegram's Bot API gives no per-post analytics via webhook — the real,
     * honest value here is `my_chat_member`: catching the bot being removed
     * or demoted out of a channel immediately, instead of waiting for the
     * next `oauth-providers:health-check` tick to notice. Anything else
     * (channel_post/edited_channel_post) is stored for idempotent audit but
     * has no further action defined yet.
     */
    private function processTelegram(PlatformWebhookEvent $event): void
    {
        $page = $this->resolveSocialPage($event);
        if ($page === null) {
            return;
        }

        $newStatus = (string) Arr::get($event->payload, 'my_chat_member.new_chat_member.status', '');
        if (! in_array($newStatus, ['left', 'kicked'], true)) {
            return;
        }

        app(TenantContext::class)->run((int) $page->organization_id, function () use ($page): void {
            $page->update(['status' => 'invalid']);
        });
    }

    private function resolveSocialPage(PlatformWebhookEvent $event): ?SocialPage
    {
        if ($event->social_page_id !== null) {
            return SocialPage::withoutGlobalScope(OrganizationScope::class)->find($event->social_page_id);
        }

        return null;
    }
}
