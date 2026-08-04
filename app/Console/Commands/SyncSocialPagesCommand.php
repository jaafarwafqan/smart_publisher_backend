<?php

namespace App\Console\Commands;

use App\Infrastructure\ExternalServices\Publishing\SocialPageSyncService;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Services\ContextLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class SyncSocialPagesCommand extends Command
{
    protected $signature = 'social-pages:sync';

    protected $description = 'Re-discover or re-verify every connected account\'s pages/channels and detect additions, updates, or lost access.';

    /**
     * Scheduled, no HTTP request — scans across every organization's
     * connected accounts (global-scope bypass, the sanctioned system-job
     * exception), then runs each account's own sync inside
     * TenantContext::run($account's own organization_id) so
     * SocialPageSyncService's SocialPage::updateOrCreate calls are scoped
     * correctly.
     */
    public function handle(SocialPageSyncService $syncService): int
    {
        $accounts = SocialAccount::withoutGlobalScope(OrganizationScope::class)
            ->where('is_active', true)
            ->where('status', 'connected')
            ->get();

        foreach ($accounts as $account) {
            app(TenantContext::class)->run((int) $account->organization_id, function () use ($syncService, $account): void {
                try {
                    $result = $account->isAutoDiscovery()
                        ? $syncService->syncAuto($account)
                        : $syncService->syncManual($account);

                    $this->info("Account #{$account->id} ({$account->provider}): ".json_encode($result));
                } catch (Throwable $e) {
                    $this->error("Account #{$account->id} ({$account->provider}) sync failed: {$e->getMessage()}");
                    ContextLogger::error('social_pages.sync.failed', [
                        'social_account_id' => $account->id,
                        'provider' => $account->provider,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        return self::SUCCESS;
    }
}
