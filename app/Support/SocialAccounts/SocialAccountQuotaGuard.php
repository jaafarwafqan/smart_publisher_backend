<?php

namespace App\Support\SocialAccounts;

use App\Exceptions\Api\ApiException;
use App\Models\SocialPage;
use App\Support\Billing\OrganizationEntitlements;
use App\Support\Billing\QuotaGates;
use App\Support\Tenancy\TenantContext;

/**
 * Single shared enforcement point for max_social_accounts. Previously only
 * TelegramBotConnector checked this quota (counting SocialAccount rows) —
 * SocialAccountController::callback()/nativeConnect() (Facebook, Instagram,
 * WhatsApp, X — the platform's two most-used providers) bypassed it
 * entirely.
 *
 * DECISION (documented per the 2026-08 quota-gap review): the counted unit
 * is SocialPage rows with is_selected = true, not SocialAccount rows. A
 * single Facebook account can hold ten Pages; a Page (or Telegram channel)
 * is the actual publish destination a plan's capacity is meant to bound,
 * not the OAuth account used to discover it. "max_social_accounts" keeps
 * its existing key/name (no migration of stored plan data) — only what it
 * measures changed.
 *
 * Two kinds of enforcement follow from that:
 *   - assertRoomToConnect(): a coarse pre-check at connect time
 *     (callback/nativeConnect/TelegramBotConnector), against the CURRENT
 *     selected-page count. Connecting a raw OAuth account doesn't add a
 *     page by itself, but there is no reason to let a connection through
 *     when the organization already has zero remaining capacity — failing
 *     fast here avoids a successful OAuth handshake immediately followed by
 *     a blocked page selection.
 *   - assertCanSelect(): the real, precise enforcement. selectPages() and
 *     addPage() are the only two actions that actually mark a page
 *     "selected" (auto-discovery) or create an already-selected one
 *     (Telegram's manual add, which has no separate selection step) — see
 *     both call sites in SocialAccountController.
 */
final class SocialAccountQuotaGuard
{
    public function __construct(private readonly OrganizationEntitlements $entitlements) {}

    public function assertRoomToConnect(): void
    {
        $organizationId = app(TenantContext::class)->get();

        if ($this->entitlements->hasCapacityFor($organizationId, QuotaGates::SOCIAL_ACCOUNTS, $this->selectedPageCount())) {
            return;
        }

        $this->reject();
    }

    /** @param  int  $resultingSelectedCount  Total selected-page count the caller's action would produce. */
    public function assertCanSelect(int $resultingSelectedCount): void
    {
        $organizationId = app(TenantContext::class)->get();
        $limit = $this->entitlements->limitFor($organizationId, QuotaGates::SOCIAL_ACCOUNTS);

        if ($limit === null || $resultingSelectedCount <= $limit) {
            return;
        }

        $this->reject();
    }

    public function selectedPageCount(): int
    {
        // Relies on SocialPage's own BelongsToOrganization scope, same
        // convention TelegramBotConnector's original SocialAccount::count()
        // used — no explicit organization_id filter needed.
        return SocialPage::query()->where('is_selected', true)->count();
    }

    private function reject(): never
    {
        throw new ApiException(
            'Your organization has reached its connected social account limit for the current plan.',
            [
                'message' => 'Your organization has reached its connected social account limit for the current plan.',
                'code' => 'social_account_quota_exceeded',
            ],
            422,
        );
    }
}
