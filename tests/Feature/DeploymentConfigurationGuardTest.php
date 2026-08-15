<?php

namespace Tests\Feature;

use App\Support\Deployment\DeploymentConfigurationGuard;
use RuntimeException;
use Tests\TestCase;

class DeploymentConfigurationGuardTest extends TestCase
{
    public function test_staging_allows_an_s3_compatible_disk_for_separate_workers(): void
    {
        $this->configureSafeStaging();
        config()->set('deployment.separate_queue_worker', true);
        config()->set('filesystems.media_upload_disk', 'shared-media');
        config()->set('filesystems.disks.shared-media', ['driver' => 's3']);

        DeploymentConfigurationGuard::assertSafeConfiguration($this->app);

        $this->assertTrue(true);
    }

    public function test_staging_rejects_a_missing_media_disk_for_separate_workers(): void
    {
        $this->configureSafeStaging();
        config()->set('deployment.separate_queue_worker', true);
        config()->set('filesystems.media_upload_disk', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEDIA_UPLOAD_DISK must name an S3-compatible disk');

        DeploymentConfigurationGuard::assertSafeConfiguration($this->app);
    }

    public function test_staging_rejects_local_media_for_separate_workers(): void
    {
        $this->configureSafeStaging();
        config()->set('deployment.separate_queue_worker', true);
        config()->set('filesystems.media_upload_disk', 'local');
        config()->set('filesystems.disks.local', ['driver' => 'local']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEDIA_UPLOAD_DISK must name an S3-compatible disk');

        DeploymentConfigurationGuard::assertSafeConfiguration($this->app);
    }

    public function test_production_rejects_a_non_shared_media_disk_for_separate_workers(): void
    {
        $this->configureSafeStaging();
        app()->instance('env', 'production');
        config()->set('deployment.separate_queue_worker', true);
        config()->set('filesystems.media_upload_disk', 'local');
        config()->set('filesystems.disks.local', ['driver' => 'local']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEDIA_UPLOAD_DISK must name an S3-compatible disk');

        DeploymentConfigurationGuard::assertSafeConfiguration($this->app);
    }

    public function test_local_development_remains_usable_without_shared_media(): void
    {
        app()->instance('env', 'local');
        config()->set('deployment.separate_queue_worker', true);
        config()->set('filesystems.media_upload_disk', 'local');
        config()->set('filesystems.disks.local', ['driver' => 'local']);

        DeploymentConfigurationGuard::assertSafeConfiguration($this->app);

        $this->assertTrue(true);
    }

    private function configureSafeStaging(): void
    {
        app()->instance('env', 'staging');
        config()->set('app.url', 'https://api.staging.example.test');
        config()->set('app.debug', false);
        config()->set('security.require_https', true);
        config()->set('session.secure', true);
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'no-reply@example.test');
        config()->set('cors.allowed_origins', ['https://staging.example.test']);
    }
}
