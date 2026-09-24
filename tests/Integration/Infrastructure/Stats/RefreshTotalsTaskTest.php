<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Stats;

use Amp\NullCancellation;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Stats\RefreshTotalsTask;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\FakeChannel;
use PDO;
use PHPUnit\Framework\TestCase;

final class RefreshTotalsTaskTest extends TestCase
{
    private string $databasePath;
    private SqliteDatabase $database;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'hubstr-relay-refresh-totals-');
        $this->database = SqliteDatabase::atPath($this->databasePath);
        $this->pdo = $this->database->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        @unlink($this->databasePath);
    }

    public function testRunReturnsPersistCommandForTotals(): void
    {
        $task = new RefreshTotalsTask($this->database);

        $result = $task->run(new FakeChannel(), new NullCancellation());

        $result->applyTo(WriteContext::forConnection($this->pdo));
        $cached = new StatResultsStore(new StatementRunner($this->pdo))->find(StatKey::totals());
        self::assertNotNull($cached);
        self::assertStringContainsString('"events":0', $cached->getPayload());
    }

    public function testTaskIsSerialisable(): void
    {
        $task = new RefreshTotalsTask($this->database);

        $restored = unserialize(serialize($task));

        self::assertInstanceOf(RefreshTotalsTask::class, $restored);
    }
}
