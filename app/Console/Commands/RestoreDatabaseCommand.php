<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class RestoreDatabaseCommand extends Command
{
    protected $signature = 'app:restore-database {file : Path to a backup file created by app:backup-database} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the application database from a backup file created by app:backup-database. Overwrites current data.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! file_exists($file)) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will OVERWRITE the current database with the contents of this backup. Continue?'
        )) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            $this->error("Unknown database connection: {$connectionName}");

            return self::FAILURE;
        }

        $driver = $connection['driver'] ?? null;

        $result = match ($driver) {
            'sqlite' => $this->restoreSqlite($connectionName, $connection, $file),
            'mysql' => $this->restoreMysql($connection, $file),
            default => $this->unsupportedDriver((string) $driver),
        };

        if ($result === self::SUCCESS) {
            $this->newLine();
            $this->warn(
                'Reminder: if this backup was taken under a different APP_KEY than the one currently '
                .'configured, the encrypted SocialAccount access/refresh token columns are now undecryptable '
                .'— those social accounts will need to be reconnected.'
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function restoreSqlite(string $connectionName, array $connection, string $file): int
    {
        $target = $connection['database'] ?? null;

        if (! is_string($target) || $target === '') {
            $this->error('Current sqlite connection has no database path configured.');

            return self::FAILURE;
        }

        if ($target === ':memory:') {
            $this->error(
                'Cannot restore into an in-memory (":memory:") sqlite database — '
                .'there is no file to swap. This is only meaningful for a file-backed database.'
            );

            return self::FAILURE;
        }

        // Close the app's own connection first so its file handle doesn't
        // conflict with overwriting the file underneath it.
        DB::disconnect($connectionName);

        File::ensureDirectoryExists(dirname($target));
        File::copy($file, $target);

        $this->info("Restored SQLite database from {$file}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function restoreMysql(array $connection, string $file): int
    {
        $availability = Process::run(['mysql', '--version']);

        if (! $availability->successful()) {
            $this->error('mysql client is not available on PATH — cannot restore the MySQL database.');

            return self::FAILURE;
        }

        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s %s < %s',
            escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($connection['port'] ?? 3306)),
            escapeshellarg((string) ($connection['username'] ?? 'root')),
            escapeshellarg((string) $connection['database']),
            escapeshellarg($file)
        );

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->run($command);

        if (! $result->successful()) {
            $this->error('mysql restore failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $this->info("Restored MySQL database from {$file}");

        return self::SUCCESS;
    }

    private function unsupportedDriver(string $driver): int
    {
        $this->error("No restore strategy implemented for database driver: {$driver}");

        return self::FAILURE;
    }
}
