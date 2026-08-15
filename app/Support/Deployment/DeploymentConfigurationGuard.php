<?php

namespace App\Support\Deployment;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

final class DeploymentConfigurationGuard
{
    /**
     * Refuse insecure or impossible staging/production configurations before
     * the web server, queue worker, or scheduler can start.
     */
    public static function assertSafeConfiguration(Application $app): void
    {
        if (! $app->environment(['staging', 'production'])) {
            return;
        }

        $violations = [];
        $appUrl = (string) config('app.url');
        $appUrlIsHttps = filter_var($appUrl, FILTER_VALIDATE_URL)
            && parse_url($appUrl, PHP_URL_SCHEME) === 'https';

        if (config('app.debug')) {
            $violations[] = 'APP_DEBUG must be false';
        }

        if (! $appUrlIsHttps) {
            $violations[] = 'APP_URL must be an HTTPS URL';
        }

        if (! config('security.require_https')) {
            $violations[] = 'SECURITY_REQUIRE_HTTPS must be true';
        }

        if (! config('session.secure')) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true';
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['array', 'failover', 'log'], true)) {
            $violations[] = 'MAIL_MAILER must deliver mail through a real provider';
        }

        if (! filter_var((string) config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            $violations[] = 'MAIL_FROM_ADDRESS must be a valid email address';
        }

        $origins = config('cors.allowed_origins', []);
        $originsAreExplicitHttps = is_array($origins)
            && $origins !== []
            && array_all(
                $origins,
                fn (mixed $origin): bool => is_string($origin) && str_starts_with($origin, 'https://'),
            );

        if (! $originsAreExplicitHttps) {
            $violations[] = 'CORS_ALLOWED_ORIGINS must contain explicit HTTPS origins only';
        }

        if (config('deployment.separate_queue_worker')) {
            $mediaUploadDisk = config('filesystems.media_upload_disk');
            $mediaDisk = is_string($mediaUploadDisk) && $mediaUploadDisk !== ''
                ? config("filesystems.disks.{$mediaUploadDisk}")
                : null;

            if (! is_array($mediaDisk) || ($mediaDisk['driver'] ?? null) !== 's3') {
                $violations[] = 'MEDIA_UPLOAD_DISK must name an S3-compatible disk when SP_SEPARATE_QUEUE_WORKER is true';
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Refusing to start with unsafe '.$app->environment().' configuration: '.implode('; ', $violations).'.'
            );
        }
    }
}
