<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\EventIdCount;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachingExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultCache;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use Innis\Hubstr\Relay\Tests\Fake\RecordingExploreQuery;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CachingExploreQueryTest extends TestCase
{
    private PDO $pdo;
    private StatResultsStore $cache;
    private RecordingExploreQuery $underlying;
    private CachingExploreQuery $store;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->cache = new StatResultsStore(new StatementRunner($this->pdo));
        $this->underlying = new RecordingExploreQuery();
        $this->store = new CachingExploreQuery(
            $this->underlying,
            new StatResultCache($this->cache, new WriteCoordinator(new DirectWriteChannel($this->pdo))),
            new StatPayloadCodec(),
        );
    }

    public function testFirstCallInvokesUnderlyingAndPopulatesCache(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [
            new HashtagCount(Hashtag::fromString('nostr'), 5),
            new HashtagCount(Hashtag::fromString('bitcoin'), 3),
        ];

        $results = $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(2, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('nostr', (string) $results[0]->getHashtag());
        $this->assertSame(5, $results[0]->getCount());
        $this->assertSame(1, $this->underlying->callCount(StatName::TrendingHashtags));
        $this->assertNotNull($this->cache->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All)));
    }

    public function testSecondCallReadsFromCacheWithoutInvokingUnderlying(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('nostr'), 5)];

        $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();
        $results = $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertSame(1, $this->underlying->callCount(StatName::TrendingHashtags));
        $this->assertCount(1, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('nostr', (string) $results[0]->getHashtag());
    }

    public function testStaleCacheIsServedWithoutRecomputation(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('old'), 1)];
        $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->pdo->exec('UPDATE stat_results SET computed_at = 0');
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('fresh'), 9)];

        $results = $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertSame(1, $this->underlying->callCount(StatName::TrendingHashtags));
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('old', (string) $results[0]->getHashtag());
    }

    public function testMissingCacheRowComputesFromUnderlying(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('old'), 1)];
        $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->pdo->exec('DELETE FROM stat_results');
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('fresh'), 9)];

        $results = $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertSame(2, $this->underlying->callCount(StatName::TrendingHashtags));
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('fresh', (string) $results[0]->getHashtag());
    }

    public function testCachedResultIsSlicedByRequestedLimit(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [
            new HashtagCount(Hashtag::fromString('a'), 3),
            new HashtagCount(Hashtag::fromString('b'), 2),
            new HashtagCount(Hashtag::fromString('c'), 1),
        ];

        $results = $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 2)->toArray();

        $this->assertCount(2, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertInstanceOf(HashtagCount::class, $results[1]);
        $this->assertSame('a', (string) $results[0]->getHashtag());
        $this->assertSame('b', (string) $results[1]->getHashtag());
    }

    public function testPubkeyEntriesRoundTripThroughCache(): void
    {
        $pubkey = PublicKey::tryFromHex(str_repeat('a', 64))
            ?? throw new RuntimeException('Invalid test pubkey');
        $this->underlying->resultsByStat[StatName::MostFollowed->value] = [new PubkeyCount($pubkey, 7)];

        $this->store->findByStat(StatName::MostFollowed, ExplorePeriod::All, 10)->toArray();
        $results = $this->store->findByStat(StatName::MostFollowed, ExplorePeriod::All, 10)->toArray();

        $this->assertSame(1, $this->underlying->callCount(StatName::MostFollowed));
        $this->assertCount(1, $results);
        $this->assertInstanceOf(PubkeyCount::class, $results[0]);
        $this->assertSame($pubkey->toHex(), $results[0]->getPubkey()->toHex());
        $this->assertSame(7, $results[0]->getCount());
    }

    public function testEventIdEntriesRoundTripThroughCache(): void
    {
        $eventId = EventId::tryFromHex(str_repeat('b', 64))
            ?? throw new RuntimeException('Invalid test event id');
        $this->underlying->resultsByStat[StatName::MostZappedNotes->value] = [new EventIdCount($eventId, 4)];

        $this->store->findByStat(StatName::MostZappedNotes, ExplorePeriod::All, 10)->toArray();
        $results = $this->store->findByStat(StatName::MostZappedNotes, ExplorePeriod::All, 10)->toArray();

        $this->assertSame(1, $this->underlying->callCount(StatName::MostZappedNotes));
        $this->assertCount(1, $results);
        $this->assertInstanceOf(EventIdCount::class, $results[0]);
        $this->assertSame($eventId->toHex(), $results[0]->getEventId()->toHex());
        $this->assertSame(4, $results[0]->getCount());
    }

    public function testEachStatHasSeparateCacheKey(): void
    {
        $this->underlying->resultsByStat[StatName::TrendingHashtags->value] = [new HashtagCount(Hashtag::fromString('hashes'), 1)];
        $pubkey = PublicKey::tryFromHex(str_repeat('c', 64))
            ?? throw new RuntimeException('Invalid test pubkey');
        $this->underlying->resultsByStat[StatName::MostFollowed->value] = [new PubkeyCount($pubkey, 2)];

        $this->store->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();
        $this->store->findByStat(StatName::MostFollowed, ExplorePeriod::All, 10)->toArray();

        $this->assertNotNull($this->cache->find(StatKey::forStat(StatName::TrendingHashtags, ExplorePeriod::All)));
        $this->assertNotNull($this->cache->find(StatKey::forStat(StatName::MostFollowed, ExplorePeriod::All)));
    }
}
