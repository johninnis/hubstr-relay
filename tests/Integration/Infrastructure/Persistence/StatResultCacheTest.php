<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultCache;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use PHPUnit\Framework\TestCase;

final class StatResultCacheTest extends TestCase
{
    private StatResultCache $cache;

    protected function setUp(): void
    {
        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->cache = new StatResultCache(new StatResultsStore(new StatementRunner($pdo)), new WriteCoordinator(new DirectWriteChannel($pdo)));
    }

    public function testAMissIsNull(): void
    {
        self::assertNull($this->cache->find(StatKey::totals()));
    }

    public function testASavedPayloadIsFoundUnderItsKey(): void
    {
        $key = StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::Week);

        $this->cache->save($key, '[{"hashtag":"nostr","count":3}]');

        self::assertSame('[{"hashtag":"nostr","count":3}]', $this->cache->find($key));
    }

    public function testSavingAgainReplacesThePayload(): void
    {
        $this->cache->save(StatKey::totals(), '{"events":1}');
        $this->cache->save(StatKey::totals(), '{"events":2}');

        self::assertSame('{"events":2}', $this->cache->find(StatKey::totals()));
    }
}
