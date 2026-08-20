<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Predis\Client;
use Tests\TestCase;

/**
 * Production topology contract. SQLite is intentionally kept for the feature
 * suite, while the deployment manifest and config must keep high-contention
 * cache/lock/session/queue traffic on Redis instead of the MySQL primary.
 */
class RedisServicesTopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_is_a_supported_client_without_a_php_extension(): void
    {
        self::assertTrue(class_exists(Client::class));
        self::assertArrayHasKey('redis', config('cache.stores'));
        self::assertArrayHasKey('redis', config('queue.connections'));
        self::assertArrayHasKey('default', config('database.redis'));
        self::assertArrayHasKey('cache', config('database.redis'));
    }

    public function test_production_service_drivers_can_be_switched_to_redis_without_database_tables(): void
    {
        config()->set('cache.default', 'redis');
        config()->set('queue.default', 'redis');
        config()->set('session.driver', 'redis');
        config()->set('database.redis.client', 'predis');
        config()->set('queue.connections.redis.retry_after', 120);

        self::assertSame('redis', config('cache.default'));
        self::assertSame('redis', config('queue.default'));
        self::assertSame('redis', config('session.driver'));
        self::assertSame('predis', config('database.redis.client'));
        self::assertGreaterThan(60, config('queue.connections.redis.retry_after'));
    }
}
