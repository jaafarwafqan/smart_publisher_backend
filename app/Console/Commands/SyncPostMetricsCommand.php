<?php

namespace App\Console\Commands;

use App\Infrastructure\ExternalServices\Publishing\PostMetricsSyncService;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\Scopes\OrganizationScope;
use App\Services\ContextLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class SyncPostMetricsCommand extends Command
{
    protected $signature = 'post-metrics:sync';

    protected $description = 'Fetch real engagement metrics for recently published posts.';

    /**
     * A scheduled command with no HTTP request/authenticated user — like
     * ProcessScheduledPostsJob, this legitimately scans across every
     * organization's attempts, then sets TenantContext explicitly per
     * attempt (resolved via its own post's organization_id, looked up with
     * the scope bypassed since nothing is set yet at that point) before any
     * scoped work (PostMetric::updateOrCreate inside the sync service) runs.
     */
    public function handle(PostMetricsSyncService $syncService): int
    {
        // Metrics matter most in the first week after publishing and this
        // bounds how many provider API calls accumulate over the app's
        // lifetime — older attempts are simply left with whatever metrics
        // were last fetched for them.
        // PostPublicationAttempt is now tenant-scoped too (Sprint 3) — this
        // scan is the same sanctioned cross-tenant exception as the post_id
        // lookup below, for the same reason: nothing is set yet at this point.
        $attempts = PostPublicationAttempt::withoutGlobalScope(OrganizationScope::class)
            ->where('status', 'success')
            ->whereNotNull('social_page_id')
            ->where('processed_at', '>=', now()->subDays(7))
            ->get();

        foreach ($attempts as $attempt) {
            $organizationId = $attempt->organization_id ?? Post::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($attempt->post_id)
                ->value('organization_id');

            if ($organizationId === null) {
                continue;
            }

            app(TenantContext::class)->run((int) $organizationId, function () use ($syncService, $attempt): void {
                try {
                    $syncService->syncAttempt($attempt);
                    $this->info("Post #{$attempt->post_id} / page #{$attempt->social_page_id}: synced");
                } catch (Throwable $e) {
                    $this->error("Post #{$attempt->post_id} / page #{$attempt->social_page_id}: {$e->getMessage()}");
                    ContextLogger::error('post_metrics.sync.failed', [
                        'post_id' => $attempt->post_id,
                        'social_page_id' => $attempt->social_page_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        return self::SUCCESS;
    }
}
