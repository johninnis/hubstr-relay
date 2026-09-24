<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachedStatResult;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class StatResultsStoreTest extends TestCase
{
    private PDO $pdo;
    private StatResultsStore $store;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->store = new StatResultsStore(new StatementRunner($this->pdo));
    }

    public function testFindReturnsNullOnMiss(): void
    {
        $this->assertNull($this->store->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All)));
    }

    public function testSaveAndFindRoundTrip(): void
    {
        $this->store->save(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::Day), new CachedStatResult(1700000000, '[{"hashtag":"nostr","count":3}]'));

        $result = $this->store->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::Day));

        $this->assertNotNull($result);
        $this->assertSame(1700000000, $result->getComputedAt());
        $this->assertSame('[{"hashtag":"nostr","count":3}]', $result->getPayload());
    }

    public function testSaveOverwritesExistingEntry(): void
    {
        $this->store->save(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All), new CachedStatResult(1000, '[]'));
        $this->store->save(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All), new CachedStatResult(2000, '[{"hashtag":"x","count":1}]'));

        $result = $this->store->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All));

        $this->assertNotNull($result);
        $this->assertSame(2000, $result->getComputedAt());
        $this->assertSame('[{"hashtag":"x","count":1}]', $result->getPayload());
    }

    public function testFindIsScopedByPeriod(): void
    {
        $this->store->save(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::Day), new CachedStatResult(1000, '[{"hashtag":"daily","count":1}]'));
        $this->store->save(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All), new CachedStatResult(1000, '[{"hashtag":"ever","count":5}]'));

        $day = $this->store->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::Day));
        $all = $this->store->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All));

        $this->assertNotNull($day);
        $this->assertNotNull($all);
        $this->assertStringContainsString('daily', $day->getPayload());
        $this->assertStringContainsString('ever', $all->getPayload());
    }
}
