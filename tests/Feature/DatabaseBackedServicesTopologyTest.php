<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\PublishPostJob;
use App\Jobs\ReclaimStalePublishAttemptsJob;
use App\Jobs\RefreshSocialAccountTokenJob;
use App\Jobs\RetryDeadLetteredAttemptJob;
use App\Jobs\RetryDuePublishAttemptsJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the production deployment topology: cache, sessions, and all
 * queued work are database-backed. The regular suite normally uses sync and
 * array drivers for speed, so this test deliberately exercises Laravel's
 * real database queue connector against the migrated SQLite schema.
 */
class DatabaseBackedServicesTopologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', null);
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('queue.connections.database.queue', 'default');
        config()->set('queue.connections.database.retry_after', 120);
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.connection', null);
        config()->set('cache.stores.database.table', 'cache');
        config()->set('cache.stores.database.lock_connection', null);
        config()->set('cache.stores.database.lock_table', 'cache_locks');
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);
        config()->set('session.table', 'sessions');
    }

    public function test_required_database_backed_service_tables_are_migrated(): void
    {
        foreach (['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected migrated table [{$table}] to exist.");
        }
    }

    public function test_cache_and_lock_use_the_database_tables(): void
    {
        $cache = Cache::store('database');
        $cache->put('database-topology-cache-key', 'value', now()->addMinute());

        self::assertSame('value', $cache->get('database-topology-cache-key'));
        self::assertDatabaseCount('cache', 1);

        $lock = $cache->lock('database-topology-lock-key', 30);
        self::assertTrue($lock->get());
        self::assertDatabaseCount('cache_locks', 1);
        $lock->release();
    }

    public function test_every_application_queue_is_persisted_by_the_database_driver(): void
    {
        $dispatcher = app(Dispatcher::class);

        $dispatcher->dispatch(new PublishPostJob(1, 2, 'database-topology-batch', 3, 4));
        $dispatcher->dispatch(new RetryDeadLetteredAttemptJob(4, 3));
        $dispatcher->dispatch(new ProcessScheduledPostsJob);
        $dispatcher->dispatch(new RetryDuePublishAttemptsJob);
        $dispatcher->dispatch(new ReclaimStalePublishAttemptsJob);
        $dispatcher->dispatch(new RefreshSocialAccountTokenJob(5, 3));

        self::assertSame(2, DB::table('jobs')->where('queue', 'publishing')->count());
        self::assertSame(4, DB::table('jobs')->where('queue', 'default')->count());
        self::assertSame(
            ['default', 'publishing'],
            DB::table('jobs')->distinct()->orderBy('queue')->pluck('queue')->all(),
        );

        $jobClasses = DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(fn (string $payload): ?string => json_decode($payload, true)['data']['commandName'] ?? null)
            ->all();

        self::assertContains(PublishPostJob::class, $jobClasses);
        self::assertContains(RetryDeadLetteredAttemptJob::class, $jobClasses);
        self::assertContains(ProcessScheduledPostsJob::class, $jobClasses);
        self::assertContains(RetryDuePublishAttemptsJob::class, $jobClasses);
        self::assertContains(ReclaimStalePublishAttemptsJob::class, $jobClasses);
        self::assertContains(RefreshSocialAccountTokenJob::class, $jobClasses);
    }
}
