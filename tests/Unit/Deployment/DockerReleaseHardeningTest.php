<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class DockerReleaseHardeningTest extends TestCase
{
    public function test_docker_build_context_cannot_include_runtime_credentials(): void
    {
        $dockerIgnore = $this->projectFile('.dockerignore');

        self::assertFileExists($dockerIgnore);
        $patterns = array_filter(array_map('trim', file($dockerIgnore, FILE_IGNORE_NEW_LINES)));

        self::assertContains('.env', $patterns);
        self::assertContains('.env.*', $patterns);
        self::assertContains('*.env', $patterns);
        self::assertContains('*.pem', $patterns);
        self::assertContains('*.key', $patterns);
        self::assertContains('auth.json', $patterns);
    }

    public function test_image_uses_a_runtime_entrypoint_and_non_root_user(): void
    {
        $dockerfile = $this->contentsOf('docker/Dockerfile');

        self::assertDoesNotMatchRegularExpression(
            '/php\s+artisan\s+(?:config:cache|route:cache|optimize)\b/i',
            $dockerfile,
        );
        self::assertDoesNotMatchRegularExpression('/^\s*(?:COPY|ADD)\s+.*\.env\b/im', $dockerfile);
        self::assertStringContainsString('COPY --chown=www-data:www-data . .', $dockerfile);
        self::assertStringContainsString('USER www-data', $dockerfile);
        self::assertStringContainsString('ENTRYPOINT ["/usr/local/bin/entrypoint"]', $dockerfile);
    }

    public function test_runtime_entrypoint_caches_after_environment_injection(): void
    {
        $entrypoint = $this->contentsOf('docker/entrypoint.sh');
        $configCache = strpos($entrypoint, 'php artisan config:cache --no-interaction');
        $routeCache = strpos($entrypoint, 'php artisan route:cache --no-interaction');
        $exec = strpos($entrypoint, 'exec "$@"');

        self::assertStringContainsString('APP_KEY must be injected at runtime', $entrypoint);
        self::assertStringContainsString('php artisan package:discover --ansi --no-interaction', $entrypoint);
        self::assertIsInt($configCache);
        self::assertIsInt($routeCache);
        self::assertIsInt($exec);
        self::assertLessThan($exec, $configCache);
        self::assertLessThan($exec, $routeCache);
    }

    public function test_compose_requires_runtime_secrets_and_keeps_data_services_private(): void
    {
        $compose = $this->contentsOf('docker/docker-compose.yml');

        self::assertStringNotContainsString('change_me', $compose);
        self::assertDoesNotMatchRegularExpression(
            '/\$\{(?:APP_KEY|DB_PASSWORD|DB_ROOT_PASSWORD|REDIS_PASSWORD)[^}]*:-/',
            $compose,
        );
        self::assertMatchesRegularExpression('/\$\{APP_KEY:\?[^}]+}/', $compose);
        self::assertMatchesRegularExpression('/\$\{DB_PASSWORD:\?[^}]+}/', $compose);
        self::assertMatchesRegularExpression('/\$\{DB_ROOT_PASSWORD:\?[^}]+}/', $compose);
        self::assertMatchesRegularExpression('/\$\{REDIS_PASSWORD:\?[^}]+}/', $compose);
        self::assertStringContainsString('app_storage:/var/www/html/storage', $compose);
        self::assertStringNotContainsString('"3306:3306"', $compose);
        self::assertStringNotContainsString('"6379:6379"', $compose);
    }

    public function test_ci_runs_the_publishing_reliability_suite_against_mysql(): void
    {
        $workflow = $this->contentsOf('.github/workflows/ci.yml');
        $jobOffset = strpos($workflow, '  mysql-publishing-reliability:');

        self::assertIsInt($jobOffset);
        $mysqlJob = substr($workflow, $jobOffset);

        self::assertStringContainsString('image: mysql:8.4', $mysqlJob);
        self::assertStringContainsString('pdo_mysql', $mysqlJob);
        self::assertStringContainsString('php artisan migrate:fresh --force', $mysqlJob);
        self::assertStringContainsString('PublishingReliabilityAcceptanceTest.php', $mysqlJob);
        self::assertStringContainsString('MultiTargetPublicationBatchCompletionTest.php', $mysqlJob);
        self::assertStringContainsString('MySqlPublishingConcurrencyTest.php', $mysqlJob);
        self::assertStringContainsString('CrossUserIsolationTest.php', $mysqlJob);
        self::assertStringNotContainsString('continue-on-error', $mysqlJob);
    }

    public function test_ci_secret_scan_is_a_fail_closed_gate(): void
    {
        $workflow = $this->contentsOf('.github/workflows/ci.yml');
        $secretScanStart = strpos($workflow, '      - name: Secret scan (gitleaks)');
        $nextStep = strpos($workflow, '      - name: Setup PHP', $secretScanStart);

        self::assertIsInt($secretScanStart);
        self::assertIsInt($nextStep);
        $secretScan = substr($workflow, $secretScanStart, $nextStep - $secretScanStart);

        self::assertStringContainsString('gitleaks/gitleaks-action@v2', $secretScan);
        self::assertStringContainsString('GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $secretScan);
        self::assertStringNotContainsString('continue-on-error', $secretScan);
    }

    public function test_ci_phpstan_is_a_fail_closed_gate(): void
    {
        $workflow = $this->contentsOf('.github/workflows/ci.yml');
        $staticAnalysisStart = strpos($workflow, '      - name: Static analysis (PHPStan/Larastan)');
        $nextStep = strpos($workflow, '      - name: Run test suite', $staticAnalysisStart);

        self::assertIsInt($staticAnalysisStart);
        self::assertIsInt($nextStep);

        $staticAnalysis = substr($workflow, $staticAnalysisStart, $nextStep - $staticAnalysisStart);

        self::assertStringContainsString('vendor/bin/phpstan analyse --memory-limit=1G', $staticAnalysis);
        self::assertStringNotContainsString('continue-on-error', $staticAnalysis);
    }

    public function test_database_production_defaults_do_not_allow_root_or_empty_credentials(): void
    {
        $databaseConfig = $this->contentsOf('config/database.php');
        $environmentTemplate = $this->contentsOf('.env.example');

        self::assertStringContainsString(
            "\$productionDatabaseFallback = env('APP_ENV') === 'production';",
            $databaseConfig,
        );
        self::assertStringContainsString(
            "env('DB_USERNAME', \$productionDatabaseFallback ? null : 'root')",
            $databaseConfig,
        );
        self::assertStringContainsString(
            "env('DB_PASSWORD', \$productionDatabaseFallback ? null : '')",
            $databaseConfig,
        );
        self::assertStringContainsString("'engine' => env('DB_ENGINE', 'InnoDB'),", $databaseConfig);
        self::assertStringNotContainsString('DB_USERNAME=root', $environmentTemplate);
        self::assertDoesNotMatchRegularExpression('/^DB_PASSWORD=\s*$/m', $environmentTemplate);
    }

    private function contentsOf(string $relativePath): string
    {
        $path = $this->projectFile($relativePath);

        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function projectFile(string $relativePath): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$relativePath;
    }
}
