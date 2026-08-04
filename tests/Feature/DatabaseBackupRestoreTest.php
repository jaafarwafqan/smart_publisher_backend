<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseBackupRestoreTest extends TestCase
{
    private string $testBackupDir = 'backups_test';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/'.$this->testBackupDir));

        parent::tearDown();
    }

    public function test_backup_command_creates_a_real_sqlite_snapshot_file(): void
    {
        // A plain file-backed sqlite connection, deliberately not the
        // framework's transaction-wrapped ":memory:" test connection —
        // SQLite's VACUUM INTO cannot run inside an open transaction, and a
        // real `php artisan app:backup-database` invocation never has one.
        $tempDbPath = storage_path('app/'.$this->testBackupDir.'/backup_only.sqlite');
        File::ensureDirectoryExists(dirname($tempDbPath));
        File::put($tempDbPath, '');

        $originalDatabase = config('database.connections.sqlite.database');
        config(['database.connections.sqlite.database' => $tempDbPath]);
        DB::purge('sqlite');

        try {
            $this->artisan('app:backup-database', ['--path' => $this->testBackupDir])
                ->assertSuccessful();

            $files = File::glob(storage_path('app/'.$this->testBackupDir.'/backup_sqlite_*.sqlite'));

            $this->assertNotEmpty($files, 'Expected a backup_sqlite_*.sqlite file to be created.');
            $this->assertGreaterThan(0, filesize($files[0]));
        } finally {
            config(['database.connections.sqlite.database' => $originalDatabase]);
            DB::purge('sqlite');
        }
    }

    public function test_restore_command_refuses_an_in_memory_database(): void
    {
        // The main test suite's own connection is ":memory:" — restoring by
        // swapping a file makes no sense for it, and the command must say
        // so clearly instead of silently doing nothing or corrupting state.
        $dummyBackup = storage_path('app/'.$this->testBackupDir.'/dummy.sqlite');
        File::ensureDirectoryExists(dirname($dummyBackup));
        File::put($dummyBackup, 'not a real backup, just needs to exist');

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->artisan('app:restore-database', ['file' => $dummyBackup, '--force' => true])
            ->assertFailed();
    }

    public function test_restore_command_rejects_a_missing_backup_file(): void
    {
        $this->artisan('app:restore-database', [
            'file' => storage_path('app/'.$this->testBackupDir.'/does_not_exist.sqlite'),
            '--force' => true,
        ])->assertFailed();
    }
}
