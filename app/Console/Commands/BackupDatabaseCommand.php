<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'app:backup-database {--path=backups : Directory under storage/app to write the backup into}';

    protected $description = 'Create a consistent backup of the application database (SQLite via VACUUM INTO, MySQL via mysqldump).';

    public function handle(): int
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            $this->error("Unknown database connection: {$connectionName}");

            return self::FAILURE;
        }

        $driver = $connection['driver'] ?? null;
        $directory = storage_path('app/'.ltrim((string) $this->option('path'), '/\\'));
        File::ensureDirectoryExists($directory);
        $timestamp = now()->format('Y-m-d_His');

        $result = match ($driver) {
            'sqlite' => $this->backupSqlite($connectionName, $connection, $directory, $timestamp),
            'mysql' => $this->backupMysql($connection, $directory, $timestamp),
            default => $this->unsupportedDriver((string) $driver),
        };

        if ($result === self::SUCCESS) {
            $this->newLine();
            $this->warn(
                'Reminder: SocialAccount access/refresh tokens are encrypted with APP_KEY. '
                .'Keep the APP_KEY that was active at backup time alongside this file — '
                .'restoring with a different key makes those columns permanently undecryptable.'
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function backupSqlite(string $connectionName, array $connection, string $directory, string $timestamp): int
    {
        $source = $connection['database'] ?? null;

        if (! is_string($source) || $source === '') {
            $this->error('No sqlite database path configured.');

            return self::FAILURE;
        }

        // VACUUM INTO reads from the live connection, not the file directly,
        // so it also works for an in-memory (":memory:") database — only a
        // real configured file path that's actually missing is an error.
        if ($source !== ':memory:' && ! file_exists($source)) {
            $this->error("SQLite database file not found: {$source}");

            return self::FAILURE;
        }

        $target = $directory.DIRECTORY_SEPARATOR."backup_sqlite_{$timestamp}.sqlite";

        // VACUUM INTO takes a clean, consistent snapshot directly via SQLite
        // itself — safe even in WAL mode, unlike a raw file copy which can
        // miss recent commits still sitting in the -wal file or catch a
        // write mid-flight.
        DB::connection($connectionName)->statement('VACUUM INTO ?', [$target]);

        if (! file_exists($target)) {
            $this->error('Backup did not produce a file.');

            return self::FAILURE;
        }

        $this->info("SQLite backup created: {$target} (".$this->humanSize((int) filesize($target)).')');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function backupMysql(array $connection, string $directory, string $timestamp): int
    {
        $availability = Process::run(['mysqldump', '--version']);

        if (! $availability->successful()) {
            $this->error('mysqldump is not available on PATH — cannot back up the MySQL database.');

            return self::FAILURE;
        }

        $target = $directory.DIRECTORY_SEPARATOR."backup_mysql_{$timestamp}.sql";

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->run([
                'mysqldump',
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? 3306),
                '--user='.($connection['username'] ?? 'root'),
                '--single-transaction',
                '--routines',
                '--result-file='.$target,
                (string) $connection['database'],
            ]);

        if (! $result->successful()) {
            $this->error('mysqldump failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        if (! file_exists($target)) {
            $this->error('Backup did not produce a file.');

            return self::FAILURE;
        }

        $this->info("MySQL backup created: {$target} (".$this->humanSize((int) filesize($target)).')');

        return self::SUCCESS;
    }

    private function unsupportedDriver(string $driver): int
    {
        $this->error("No backup strategy implemented for database driver: {$driver}");

        return self::FAILURE;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024) {
                return round($value, 2)." {$unit}";
            }
            $value /= 1024;
        }

        return round($value, 2).' TB';
    }
}
