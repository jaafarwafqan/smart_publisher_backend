<?php

namespace App\Infrastructure\ExternalServices\Publishing;

use App\Infrastructure\ExternalServices\SocialOAuth\SocialOAuthManager;
use App\Infrastructure\ExternalServices\SocialOAuth\TelegramProvider;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use RuntimeException;

class SocialPageSyncService
{
    public function __construct(private readonly SocialOAuthManager $oauthManager) {}

    /**
     * @return array{added:int, updated:int, removed:int}
     */
    public function syncAuto(SocialAccount $account): array
    {
        $remotePages = $this->oauthManager->provider($account->provider)->listPages(
            (string) $account->access_token,
            [
                'provider_account_id' => $account->provider_account_id,
                'metadata' => $account->metadata,
            ]
        );

        $seenIds = [];
        $added = 0;
        $updated = 0;

        foreach ($remotePages as $raw) {
            $pageId = (string) ($raw['page_id'] ?? '');
            if ($pageId === '') {
                continue;
            }

            $seenIds[] = $pageId;

            $page = SocialPage::query()->updateOrCreate(
                ['social_account_id' => $account->id, 'page_id' => $pageId],
                [
                    'kind' => $raw['kind'] ?? 'page',
                    'name' => $raw['name'] ?? null,
                    'picture_url' => $raw['picture_url'] ?? null,
                    'can_publish' => $raw['can_publish'] ?? true,
                    'status' => 'valid',
                    'discovery_source' => 'auto',
                    'metadata' => $raw['metadata'] ?? [],
                    'last_synced_at' => now(),
                ]
            );

            $page->wasRecentlyCreated ? $added++ : $updated++;
        }

        $removed = SocialPage::query()
            ->where('social_account_id', $account->id)
            ->when($seenIds !== [], fn ($query) => $query->whereNotIn('page_id', $seenIds))
            ->where('status', '!=', 'invalid')
            ->update(['status' => 'invalid', 'is_selected' => false]);

        $account->update(['last_synced_at' => now()]);

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * Re-verify a manually-added account's existing pages (Telegram channels
     * today) rather than discover new ones — the provider has no "list
     * everything this account can access" API, so a channel only appears
     * here once an admin adds it explicitly via addPage().
     *
     * @return array{revalidated:int, invalidated:int}
     */
    public function syncManual(SocialAccount $account): array
    {
        $revalidated = 0;
        $invalidated = 0;

        foreach ($account->pages as $page) {
            try {
                $verified = app(TelegramProvider::class)->verifyChat((string) $account->access_token, $page->page_id);
                $page->update([
                    'name' => $verified['name'],
                    'can_publish' => $verified['can_publish'],
                    'status' => $verified['can_publish'] ? 'valid' : 'needs_reauth',
                    'metadata' => $verified['metadata'] ?? [],
                    'last_synced_at' => now(),
                    'last_verified_at' => now(),
                ]);
                $revalidated++;
            } catch (RuntimeException) {
                $page->update(['status' => 'invalid', 'is_selected' => false]);
                $invalidated++;
            }
        }

        $account->update(['last_synced_at' => now()]);

        return ['revalidated' => $revalidated, 'invalidated' => $invalidated];
    }
}
