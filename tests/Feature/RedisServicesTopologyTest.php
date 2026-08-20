<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledPostsJob;
use App\Jobs\PublishPostJob;
use App\Jobs\ReclaimStalePublishAttemptsJob;
use App\Jobs\RefreshSocialAccountTokenJob;
use App\Jobs\RetryDeadLetteredAttemptJob;
use App\Jobs\RetryDuePublishAttemptsJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A true Redis topology contract. This suite is deliberately enabled only by
 * the CI Redis sidecar: a developer without Redis sees an explicit skipped
 * integration test, never a false-positive assertion about values the test
 * assigned to config itself.
 */
class RedisServicesTopologyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('REDIS_INTEGRATION_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Redis integration tests require the CI Redis service (REDIS_INTEGRATION_TESTS=true).');
        }

        config()->set('cache.default', 'redis');
        config()->set('cache.stores.redis.connection', 'cache');
        config()->set('cache.stores.redis.lock_connection', 'default');
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('queue.connections.redis.retry_after', 120);
        config()->set('session.driver', 'redis');
        config()->set('session.connection', 'default');
        config()->set('session.store', 'redis');
        config()->set('database.redis.client', 'predis');

        // Force the managers to consume the integration configuration rather
        // than an array/sync driver that an earlier test happened to resolve.
        Cache::clearResolvedInstance('cache');
        Queue::clearResolvedInstance('queue');
        app()->forgetInstance('cache');
        app()->forgetInstance('queue');
    }

    public function test_redis_persists_a_cache_value_and_distributed_lock(): void
    {
        $key = 'redis-topology:cache:'.bin2hex(random_bytes(8));
        $lockKey = 'redis-topology:lock:'.bin2hex(random_bytes(8));
        $cache = Cache::store('redis');

        $cache->put($key, 'reachable', now()->addMinute());

        self::assertSame('reachable', $cache->get($key));

        $lock = $cache->lock($lockKey, 10);
        self::assertTrue($lock->get());
        $lock->release();

        $cache->forget($key);
    }

    public function test_every_application_queue_reaches_and_is_reserved_from_redis(): void
    {
        $queue = Queue::connection('redis');
        $suffix = bin2hex(random_bytes(8));
        $publishingQueue = 'redis-topology-publishing-'.$suffix;
        $defaultQueue = 'redis-topology-default-'.$suffix;

        $jobsByQueue = [
            $publishingQueue => [
                new PublishPostJob(1, 2, 'redis-topology-batch', 3, 4),
                new RetryDeadLetteredAttemptJob(4, 3),
            ],
            $defaultQueue => [
                new ProcessScheduledPostsJob,
                new RetryDuePublishAttemptsJob,
                new ReclaimStalePublishAttemptsJob,
                new RefreshSocialAccountTokenJob(5, 3),
            ],
        ];

        foreach ($jobsByQueue as $queueName => $jobs) {
            foreach ($jobs as $job) {
                $queue->push($job, '', $queueName);
            }
        }

        foreach ($jobsByQueue as $queueName => $expectedJobs) {
            $reservedClasses = [];

            foreach ($expectedJobs as $_) {
                $reserved = $queue->pop($queueName);

                self::assertInstanceOf(Job::class, $reserved, "Expected a Redis-reserved job from [{$queueName}].");
                $reservedClasses[] = $reserved->payload()['data']['commandName'] ?? null;
                $reserved->delete();
            }

            self::assertEqualsCanonicalizing(
                array_map(static fn (object $job): string => $job::class, $expectedJobs),
                $reservedClasses,
            );
        }
    }
}
