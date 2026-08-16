<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPlatformWebhookEventJob;
use App\Models\PlatformWebhookEvent;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Support\Webhooks\FacebookWebhookSignature;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Phase 3 (webhook receiver, 2026-08-16). Real, previously non-existent —
 * see docs/api/webhooks.md's prior "RETIRED, never actually built" notice,
 * now superseded by this implementation and its own doc update.
 *
 * Deliberately unauthenticated (no auth:sanctum/tenant middleware): the
 * caller here is Facebook/Telegram's own infrastructure, not a logged-in
 * user. The trust boundary is provider-specific — a verified
 * X-Hub-Signature-256 for Facebook, a verified per-bot secret token for
 * Telegram — checked before anything is written to the database, and the
 * only writes performed synchronously here are an idempotent event-row
 * insert. All actual processing is deferred to
 * ProcessPlatformWebhookEventJob on the existing `default` database queue,
 * so this endpoint returns fast — both providers retry/backoff a slow or
 * non-2xx response, so a queued heavy path (an external Graph API call, in
 * particular) has no business running on this request.
 */
class PlatformWebhookController extends Controller
{
    /**
     * Meta's one-time subscription handshake: GET with hub.mode/
     * hub.verify_token/hub.challenge (PHP folds the dots into underscores
     * when parsing the query string — this is a query-string-parsing
     * quirk, not something Meta or this app controls). Must echo back
     * hub.challenge as a bare text body when the verify token matches
     * whatever was configured in FACEBOOK_WEBHOOK_VERIFY_TOKEN and pasted
     * into the Meta App Dashboard's Webhooks product — fails closed (403)
     * if that env var was never set, rather than accepting any caller.
     */
    public function verifyFacebook(Request $request): Response
    {
        $configuredToken = (string) config('services.facebook.webhook_verify_token', '');
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($configuredToken === '' || $mode !== 'subscribe' || ! hash_equals($configuredToken, $token)) {
            return response('Verification failed.', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receiveFacebook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $appSecret = (string) config('social.providers.facebook.client_secret', '');

        if (! FacebookWebhookSignature::verify($rawBody, $request->header('X-Hub-Signature-256'), $appSecret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawBody, true) ?? [];
        $entries = (array) Arr::get($payload, 'entry', []);

        foreach ($entries as $entry) {
            $pageId = (string) Arr::get($entry, 'id', '');
            $time = (string) Arr::get($entry, 'time', '');
            $changes = (array) Arr::get($entry, 'changes', []);
            $page = $pageId !== '' ? $this->findPageByProviderId($pageId) : null;

            foreach ($changes as $change) {
                $this->storeAndDispatch(
                    provider: 'facebook',
                    // Meta's Page webhook payload carries no single global
                    // event id of its own — a stable hash of the exact
                    // change content is the closest real equivalent, and it
                    // deliberately collapses an identical Meta redelivery
                    // (which does happen, e.g. after a slow/non-2xx
                    // response) into the same row instead of a duplicate.
                    eventId: hash('sha256', $pageId.'|'.$time.'|'.json_encode($change)),
                    type: (string) Arr::get($change, 'field', 'unknown'),
                    payload: $change,
                    page: $page,
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    public function receiveTelegram(Request $request, int $socialAccount): JsonResponse
    {
        // Deliberately NOT route-model-bound (a type-hinted SocialAccount
        // parameter would trigger implicit binding, which applies
        // OrganizationScope's global scope and throws
        // TenantContextNotSetException — there is no authenticated tenant
        // context on this unauthenticated route). Resolved manually instead,
        // same sanctioned bypass as ProcessScheduledPostsJob.
        $account = SocialAccount::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $socialAccount)
            ->where('provider', 'telegram')
            ->first();

        if ($account === null || $account->webhook_secret === null) {
            return response()->json(['message' => 'Unknown webhook endpoint.'], 404);
        }

        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($providedSecret === '' || ! hash_equals((string) $account->webhook_secret, $providedSecret)) {
            return response()->json(['message' => 'Invalid secret token.'], 401);
        }

        $payload = (array) $request->json()->all();
        $updateId = Arr::get($payload, 'update_id');
        $type = collect(['channel_post', 'edited_channel_post', 'my_chat_member', 'message', 'edited_message'])
            ->first(fn (string $key): bool => Arr::has($payload, $key)) ?? 'unknown';

        $chatId = (string) Arr::get($payload, "{$type}.chat.id", '');
        $page = $chatId !== ''
            ? SocialPage::withoutGlobalScope(OrganizationScope::class)
                ->where('social_account_id', $account->id)
                ->where('page_id', $chatId)
                ->first()
            : null;

        $this->storeAndDispatch(
            provider: 'telegram',
            eventId: $updateId !== null ? "{$account->id}:{$updateId}" : hash('sha256', $account->id.'|'.$request->getContent()),
            type: $type,
            payload: $payload,
            page: $page,
            account: $account,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeAndDispatch(
        string $provider,
        string $eventId,
        string $type,
        array $payload,
        ?SocialPage $page,
        ?SocialAccount $account = null,
    ): void {
        $isNew = true;
        $socialAccountId = $account !== null ? $account->id : $page?->social_account_id;
        $organizationId = $page !== null ? $page->organization_id : $account?->organization_id;

        try {
            $event = PlatformWebhookEvent::query()->create([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'type' => $type,
                'payload' => $payload,
                'social_account_id' => $socialAccountId,
                'social_page_id' => $page?->id,
                'organization_id' => $organizationId,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // A genuine duplicate delivery of an event we already recorded —
            // idempotent no-op, not an error the provider should see as a
            // reason to keep retrying.
            $isNew = false;
            $event = PlatformWebhookEvent::query()
                ->where('provider', $provider)
                ->where('provider_event_id', $eventId)
                ->first();
        }

        // $isNew is true only on the branch where $event was just assigned
        // from create() above (always a real model instance, never null) —
        // the catch branch's ->first() lookup (which can be null) always
        // sets $isNew = false first.
        if ($isNew) {
            ProcessPlatformWebhookEventJob::dispatch($event->id);
        }
    }

    private function findPageByProviderId(string $pageId): ?SocialPage
    {
        return SocialPage::withoutGlobalScope(OrganizationScope::class)
            ->where('page_id', $pageId)
            ->first();
    }
}
