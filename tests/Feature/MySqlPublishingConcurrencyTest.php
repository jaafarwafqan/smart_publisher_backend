<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Post;
use App\Models\PostPublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialPage;
use App\Models\User;
use App\Support\Publishing\AttemptStateMachine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * This regression deliberately uses two PHP processes and two named MySQL
 * connections. A sequential double-call proves only application logic; this
 * test makes the losing UPDATE wait behind the winner's uncommitted InnoDB
 * row lock before it can observe the state change.
 */
final class MySqlPublishingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const WORKER_A_CONNECTION = 'mysql_publish_claim_worker_a';

    private const WORKER_B_CONNECTION = 'mysql_publish_claim_worker_b';

    private const WORKER_A_ID = 'mysql-race-worker-a';

    private const WORKER_B_ID = 'mysql-race-worker-b';

    public function test_exactly_one_mysql_transaction_can_claim_a_pending_publication_attempt(): void
    {
        $baseConnectionName = DB::getDefaultConnection();
        $baseConnection = DB::connection($baseConnectionName);

        if ($baseConnection->getDriverName() === 'sqlite') {
            $this->markTestSkipped('This integration test requires MySQL/InnoDB and intentionally skips SQLite.');
        }

        self::assertSame('mysql', $baseConnection->getDriverName(), 'This concurrency test must run against MySQL.');
        self::assertTrue(function_exists('proc_open'), 'The MySQL concurrency test requires proc_open to start its second worker.');

        $baseConnectionConfig = config("database.connections.{$baseConnectionName}");
        if (! is_array($baseConnectionConfig)) {
            throw new RuntimeException("Database connection [{$baseConnectionName}] is not configured.");
        }

        // config/database.php's DB_PERSISTENT (default true since the
        // perf(db) persistent-PDO change) pools PDO handles by DSN+
        // credentials at the PHP PROCESS level — the same physical socket
        // is handed back for any connection that shares them, regardless of
        // the distinct Laravel connection *name* requesting it. Worker A's
        // config here is cloned from the exact same DSN/credentials the
        // RefreshDatabase-wrapped default connection already uses in this
        // same test process, so with persistence left on, `beginTransaction`
        // below was colliding with RefreshDatabase's own already-open
        // transaction on that shared socket ("There is already an active
        // transaction") — this whole test's premise (two genuinely
        // independent connections racing for one InnoDB row lock) requires
        // non-persistent connections here regardless of the app's own
        // production default.
        $workerConnectionConfig = $baseConnectionConfig;
        $workerConnectionConfig['options'] = array_diff_key(
            (array) ($workerConnectionConfig['options'] ?? []),
            [\PDO::ATTR_PERSISTENT => true],
        );

        config([
            'database.connections.'.self::WORKER_A_CONNECTION => $workerConnectionConfig,
            'database.connections.'.self::WORKER_B_CONNECTION => $workerConnectionConfig,
        ]);
        DB::purge(self::WORKER_A_CONNECTION);
        DB::purge(self::WORKER_B_CONNECTION);

        $workerAConnection = DB::connection(self::WORKER_A_CONNECTION);
        $originalDefaultConnection = config('database.default');
        $fixture = null;
        $raceDirectory = null;
        $process = null;
        $workerATransactionOpen = false;

        try {
            $this->assertInnoDbSchemaAssumptions($workerAConnection);

            // The fixture is intentionally committed through worker A, not the
            // RefreshDatabase transaction on the default connection. Worker B
            // is a separate process and must be able to see it.
            config(['database.default' => self::WORKER_A_CONNECTION]);
            $fixture = $this->createPendingAttemptFixture();
            app(TenantContext::class)->set($fixture['organization_id']);

            $raceDirectory = $this->createRaceDirectory();
            $readyPath = $raceDirectory.DIRECTORY_SEPARATOR.'worker-b-ready';
            $barrierPath = $raceDirectory.DIRECTORY_SEPARATOR.'start';
            $resultPath = $raceDirectory.DIRECTORY_SEPARATOR.'worker-b-result.json';
            $stdoutPath = $raceDirectory.DIRECTORY_SEPARATOR.'worker-b.stdout';
            $stderrPath = $raceDirectory.DIRECTORY_SEPARATOR.'worker-b.stderr';
            $workerScriptPath = $raceDirectory.DIRECTORY_SEPARATOR.'worker-b.php';

            file_put_contents($workerScriptPath, $this->workerBScript());

            $workerAConnection->beginTransaction();
            $workerATransactionOpen = true;

            $process = $this->startWorkerB(
                $workerScriptPath,
                $baseConnectionName,
                $fixture['attempt_id'],
                $fixture['organization_id'],
                $readyPath,
                $barrierPath,
                $resultPath,
                $stdoutPath,
                $stderrPath,
            );

            $this->waitForFile($readyPath, 5_000, 'Worker B did not start its independent transaction.');

            // Releasing both already-open transactions through a filesystem
            // barrier makes their conditional UPDATEs overlap. Each winner
            // holds its transaction briefly, forcing the other connection to
            // wait for the InnoDB row lock before it re-evaluates the WHERE.
            file_put_contents($barrierPath, 'go');

            $attemptForWorkerA = PostPublicationAttempt::on(self::WORKER_A_CONNECTION)
                ->findOrFail($fixture['attempt_id']);
            $workerAWon = (new AttemptStateMachine)->claim($attemptForWorkerA, self::WORKER_A_ID);

            if ($workerAWon) {
                usleep(250_000);
            }

            $workerAConnection->commit();
            $workerATransactionOpen = false;

            $this->waitForFile($resultPath, 10_000, 'Worker B did not finish its claim attempt.');
            $childExitCode = $this->closeWorker($process);
            $process = null;

            $workerBResult = json_decode((string) file_get_contents($resultPath), true);
            self::assertIsArray($workerBResult);
            self::assertArrayNotHasKey('error', $workerBResult, (string) file_get_contents($stderrPath));
            self::assertSame(0, $childExitCode, (string) file_get_contents($stderrPath));
            self::assertIsBool($workerBResult['won'] ?? null);
            self::assertIsInt($workerBResult['connection_id'] ?? null);

            $workerBWon = $workerBResult['won'];
            self::assertTrue($workerAWon xor $workerBWon, 'Exactly one concurrent conditional claim must succeed.');
            self::assertNotSame(
                $this->mysqlConnectionId($workerAConnection),
                $workerBResult['connection_id'],
                'Workers must use separate MySQL server connections.',
            );

            $claimedAttempt = PostPublicationAttempt::on(self::WORKER_A_CONNECTION)
                ->findOrFail($fixture['attempt_id']);
            self::assertSame('processing', $claimedAttempt->status);
            self::assertNotNull($claimedAttempt->claimed_at);
            self::assertSame(
                $workerAWon ? self::WORKER_A_ID : self::WORKER_B_ID,
                $claimedAttempt->claimed_by,
            );
        } finally {
            if ($workerATransactionOpen && $workerAConnection->transactionLevel() > 0) {
                $workerAConnection->rollBack();
            }

            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            // Keep worker A as the default while deleting the committed fixture
            // that worker B was allowed to observe, then restore the test
            // framework's transactional default before RefreshDatabase tears down.
            config(['database.default' => self::WORKER_A_CONNECTION]);
            $this->removeFixture($fixture);
            config(['database.default' => $originalDefaultConnection]);
            app(TenantContext::class)->clear();
            DB::purge(self::WORKER_A_CONNECTION);
            DB::purge(self::WORKER_B_CONNECTION);
            $this->removeRaceDirectory($raceDirectory);
        }
    }

    /**
     * @return array{attempt_id: int, organization_id: int, user_id: int}
     */
    private function createPendingAttemptFixture(): array
    {
        $nonce = bin2hex(random_bytes(8));
        $organization = Organization::query()->create([
            'name' => 'MySQL claim race '.$nonce,
            'slug' => 'mysql-claim-race-'.$nonce,
        ]);
        $organizationId = (int) $organization->getKey();

        return app(TenantContext::class)->run($organizationId, function () use ($nonce, $organizationId): array {
            // Creating the user while TenantContext is set deliberately skips
            // User::booted()'s personal-organization provisioning; this keeps
            // cleanup exact and the race fixture confined to one organization.
            $user = User::factory()->create([
                'current_organization_id' => $organizationId,
            ]);

            $post = Post::query()->create([
                'user_id' => $user->id,
                'title' => 'MySQL atomic claim race',
                'content' => 'Concurrency integration fixture.',
                'status' => 'scheduled',
                'publish_batch_key' => 'mysql-race-'.$nonce,
            ]);
            $account = SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_account_id' => 'mysql-race-account-'.$nonce,
                'status' => 'connected',
                'is_active' => true,
            ]);
            $page = SocialPage::query()->create([
                'social_account_id' => $account->id,
                'page_id' => 'mysql-race-page-'.$nonce,
                'kind' => 'page',
                'name' => 'MySQL race page',
                'can_publish' => true,
                'status' => 'valid',
            ]);
            $attempt = PostPublicationAttempt::query()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'social_page_id' => $page->id,
                'idempotency_key' => hash('sha256', 'mysql-race-'.$nonce),
                'publish_batch_key' => 'mysql-race-'.$nonce,
                'attempt_number' => 1,
                'status' => 'pending',
            ]);

            return [
                'attempt_id' => (int) $attempt->getKey(),
                'organization_id' => $organizationId,
                'user_id' => (int) $user->getKey(),
            ];
        });
    }

    /**
     * @param  array{attempt_id: int, organization_id: int, user_id: int}|null  $fixture
     */
    private function removeFixture(?array $fixture): void
    {
        if ($fixture === null) {
            return;
        }

        // All tenant-owned fixture rows cascade from its organization. The
        // users.current_organization_id FK is nullOnDelete, after which the
        // one fixture user can be removed independently.
        Organization::query()->whereKey($fixture['organization_id'])->delete();
        User::query()->whereKey($fixture['user_id'])->delete();
    }

    private function assertInnoDbSchemaAssumptions(Connection $connection): void
    {
        $databaseName = $connection->getDatabaseName();

        // information_schema is MySQL metadata, not application data access.
        $engine = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $databaseName)
            ->where('TABLE_NAME', 'post_publication_attempts')
            ->value('ENGINE');
        self::assertSame('InnoDB', $engine, 'The claim race requires InnoDB row locking.');

        $this->assertIndexColumns($connection, 'PRIMARY', ['id']);
        $this->assertIndexColumns($connection, 'post_publication_attempts_idempotency_key_unique', ['idempotency_key']);
        $this->assertIndexColumns($connection, 'ppa_org_status_next_attempt_idx', ['organization_id', 'status', 'next_attempt_at']);

        $foreignKeys = $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $databaseName)
            ->where('TABLE_NAME', 'post_publication_attempts')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('REFERENCED_TABLE_NAME', 'COLUMN_NAME')
            ->all();

        self::assertSame('posts', $foreignKeys['post_id'] ?? null);
        self::assertSame('organizations', $foreignKeys['organization_id'] ?? null);
        self::assertSame('social_accounts', $foreignKeys['social_account_id'] ?? null);
        self::assertSame('social_pages', $foreignKeys['social_page_id'] ?? null);
    }

    /**
     * @param  list<string>  $expectedColumns
     */
    private function assertIndexColumns(Connection $connection, string $indexName, array $expectedColumns): void
    {
        $columns = $connection->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'post_publication_attempts')
            ->where('INDEX_NAME', $indexName)
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        self::assertSame($expectedColumns, $columns, "Unexpected columns for MySQL index [{$indexName}].");
    }

    private function mysqlConnectionId(Connection $connection): int
    {
        // CONNECTION_ID() is the only raw statement here; it verifies that
        // worker A and the child process really use different server sessions.
        $row = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id');
        if (! is_object($row) || ! isset($row->connection_id)) {
            throw new RuntimeException('MySQL did not return a connection identifier.');
        }

        return (int) $row->connection_id;
    }

    /**
     * @return resource
     */
    private function startWorkerB(
        string $workerScriptPath,
        string $baseConnectionName,
        int $attemptId,
        int $organizationId,
        string $readyPath,
        string $barrierPath,
        string $resultPath,
        string $stdoutPath,
        string $stderrPath,
    ) {
        $command = [
            PHP_BINARY,
            $workerScriptPath,
            base_path(),
            $baseConnectionName,
            (string) $attemptId,
            (string) $organizationId,
            $readyPath,
            $barrierPath,
            $resultPath,
        ];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'ab'],
            2 => ['file', $stderrPath, 'ab'],
        ], $pipes, base_path());

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the independent MySQL worker process.');
        }

        fclose($pipes[0]);

        return $process;
    }

    /**
     * @param  resource  $process
     */
    private function closeWorker($process): int
    {
        return proc_close($process);
    }

    private function waitForFile(string $path, int $timeoutMilliseconds, string $timeoutMessage): void
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1_000);

        while (! file_exists($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($timeoutMessage);
            }

            usleep(10_000);
        }
    }

    private function createRaceDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smart-publisher-mysql-race-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the MySQL race test directory.');
        }

        return $directory;
    }

    private function removeRaceDirectory(?string $directory): void
    {
        if ($directory === null || ! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }

    private function workerBScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

[$basePath, $baseConnectionName, $attemptId, $organizationId, $readyPath, $barrierPath, $resultPath] = array_slice($argv, 1);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $baseConnectionConfig = config("database.connections.{$baseConnectionName}");
    if (! is_array($baseConnectionConfig)) {
        throw new \RuntimeException("Database connection [{$baseConnectionName}] is not configured.");
    }

    config([
        'database.connections.mysql_publish_claim_worker_b' => $baseConnectionConfig,
        'database.default' => 'mysql_publish_claim_worker_b',
    ]);
    \Illuminate\Support\Facades\DB::purge('mysql_publish_claim_worker_b');
    $connection = \Illuminate\Support\Facades\DB::connection('mysql_publish_claim_worker_b');
    app(\App\Support\Tenancy\TenantContext::class)->set((int) $organizationId);

    $connection->beginTransaction();
    file_put_contents($readyPath, 'ready');

    $deadline = microtime(true) + 5;
    while (! file_exists($barrierPath)) {
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('Worker B timed out waiting for the race barrier.');
        }

        usleep(10_000);
    }

    $attempt = \App\Models\PostPublicationAttempt::on('mysql_publish_claim_worker_b')->findOrFail((int) $attemptId);
    $won = (new \App\Support\Publishing\AttemptStateMachine())->claim($attempt, 'mysql-race-worker-b');

    if ($won) {
        usleep(250_000);
    }

    $connectionId = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id');
    if (! is_object($connectionId) || ! isset($connectionId->connection_id)) {
        throw new \RuntimeException('MySQL did not return worker B connection metadata.');
    }

    $connection->commit();
    file_put_contents($resultPath, json_encode([
        'won' => $won,
        'connection_id' => (int) $connectionId->connection_id,
    ], JSON_THROW_ON_ERROR));
} catch (\Throwable $exception) {
    if (isset($connection) && $connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    file_put_contents($resultPath, json_encode([
        'error' => $exception::class.': '.$exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
PHP;
    }
}
