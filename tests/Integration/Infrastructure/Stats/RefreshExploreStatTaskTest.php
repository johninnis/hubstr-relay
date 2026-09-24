<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Stats;

use Amp\NullCancellation;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Stats\RefreshExploreStatTask;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\FakeChannel;
use PDO;
use PHPUnit\Framework\TestCase;

final class RefreshExploreStatTaskTest extends TestCase
{
    private string $databasePath;
    private SqliteDatabase $database;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'hubstr-relay-refresh-explore-');
        $this->database = SqliteDatabase::atPath($this->databasePath);
        $this->pdo = $this->database->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        @unlink($this->databasePath);
    }

    public function testRunReturnsPersistCommandForComputedStat(): void
    {
        $task = new RefreshExploreStatTask($this->database, StatName::TrendingHashtags, ExplorePeriod::All);

        $result = $task->run(new FakeChannel(), new NullCancellation());

        $result->applyTo(WriteContext::forConnection($this->pdo));
        $cached = new StatResultsStore(new StatementRunner($this->pdo))->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All));
        self::assertNotNull($cached);
        self::assertSame('[]', $cached->getPayload());
    }

    public function testTaskIsSerialisable(): void
    {
        $task = new RefreshExploreStatTask($this->database, StatName::MostFollowed, ExplorePeriod::Week);

        $restored = unserialize(serialize($task));

        self::assertInstanceOf(RefreshExploreStatTask::class, $restored);
    }
}
