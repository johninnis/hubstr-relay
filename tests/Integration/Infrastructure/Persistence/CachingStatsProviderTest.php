<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachingStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultCache;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Hubstr\Relay\Tests\Fake\RecordingStatsProvider;
use Innis\Hubstr\Relay\Tests\Support\StatTotalsMother;
use PDO;
use PHPUnit\Framework\TestCase;

final class CachingStatsProviderTest extends TestCase
{
    private PDO $pdo;
    private StatResultsStore $cache;
    private RecordingStatsProvider $underlying;
    private CachingStatsProvider $store;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->cache = new StatResultsStore(new StatementRunner($this->pdo));
        $this->underlying = new RecordingStatsProvider();
        $this->store = new CachingStatsProvider(
            $this->underlying,
            new StatResultCache($this->cache, new WriteCoordinator(new DirectWriteChannel($this->pdo))),
            new StatPayloadCodec(),
        );
    }

    public function testFirstGetStatsCallInvokesUnderlyingAndPopulatesCache(): void
    {
        $this->underlying->stats = StatTotalsMother::withEvents(42);

        $stats = $this->store->getStats();

        $this->assertSame(42, $stats->toArray()['events']);
        $this->assertSame(1, $this->underlying->callCount);
        $this->assertNotNull($this->cache->find(StatKey::totals()));
    }

    public function testSecondGetStatsCallReadsFromCache(): void
    {
        $this->underlying->stats = StatTotalsMother::withEvents(7);

        $this->store->getStats();
        $stats = $this->store->getStats();

        $this->assertSame(1, $this->underlying->callCount);
        $this->assertSame(7, $stats->toArray()['events']);
    }

    public function testStaleCacheIsServedWithoutRecomputation(): void
    {
        $this->underlying->stats = StatTotalsMother::withEvents(1);
        $this->store->getStats();

        $this->pdo->exec('UPDATE stat_results SET computed_at = 0');
        $this->underlying->stats = StatTotalsMother::withEvents(99);

        $stats = $this->store->getStats();

        $this->assertSame(1, $this->underlying->callCount);
        $this->assertSame(1, $stats->toArray()['events']);
    }

    public function testMissingCacheRowComputesFromUnderlying(): void
    {
        $this->underlying->stats = StatTotalsMother::withEvents(1);
        $this->store->getStats();

        $this->pdo->exec('DELETE FROM stat_results');
        $this->underlying->stats = StatTotalsMother::withEvents(99);

        $stats = $this->store->getStats();

        $this->assertSame(2, $this->underlying->callCount);
        $this->assertSame(99, $stats->toArray()['events']);
    }

    public function testCachedPayloadPreservesNestedStructure(): void
    {
        $this->underlying->stats = StatTotalsMother::populated();

        $this->store->getStats();
        $second = $this->store->getStats();

        $this->assertSame($this->underlying->stats->toArray(), $second->toArray());
    }
}
