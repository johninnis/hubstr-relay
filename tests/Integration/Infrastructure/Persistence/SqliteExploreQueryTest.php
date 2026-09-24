<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\MutableClock;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteExploreQueryTest extends TestCase
{
    private PDO $pdo;
    private EventWriteStore $eventStore;
    private SqliteExploreQuery $exploreQuery;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->eventStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->exploreQuery = new SqliteExploreQuery($this->pdo);
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testPeriodCutoffComesFromInjectedClock(): void
    {
        $event = SignedEventFactory::signedEventAtTime(
            $this->keyPair,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            'old post',
            1_000_000,
            new TagCollection([Tag::tryFromArray(['t', 'nostr'])]),
        );
        $this->eventStore->store($event);

        $withinDayWindow = new SqliteExploreQuery($this->pdo, new MutableClock(1_000_100));
        $beyondDayWindow = new SqliteExploreQuery($this->pdo, new MutableClock(1_090_000));

        $this->assertCount(1, $withinDayWindow->findByStat(StatName::TrendingHashtags, ExplorePeriod::Day, 10)->toArray());
        $this->assertSame([], $beyondDayWindow->findByStat(StatName::TrendingHashtags, ExplorePeriod::Day, 10)->toArray());
    }

    public function testTrendingHashtagsReturnsEmptyForNoEvents(): void
    {
        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertSame([], $results);
    }

    public function testTrendingHashtagsCountsOccurrences(): void
    {
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'post 1', new TagCollection([
            Tag::tryFromArray(['t', 'nostr']),
            Tag::tryFromArray(['t', 'bitcoin']),
        ]));
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'post 2', new TagCollection([
            Tag::tryFromArray(['t', 'nostr']),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(2, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertInstanceOf(HashtagCount::class, $results[1]);
        $this->assertSame('nostr', (string) $results[0]->getHashtag());
        $this->assertSame(2, $results[0]->getCount());
        $this->assertSame('bitcoin', (string) $results[1]->getHashtag());
        $this->assertSame(1, $results[1]->getCount());
    }

    public function testTrendingHashtagsRespectsTimeWindow(): void
    {
        $this->storeEventAtTime(EventKind::fromInt(EventKind::TEXT_NOTE), 'old', time() - 90000, new TagCollection([
            Tag::tryFromArray(['t', 'old']),
        ]));
        $this->storeEventAtTime(EventKind::fromInt(EventKind::TEXT_NOTE), 'recent', time() - 3600, new TagCollection([
            Tag::tryFromArray(['t', 'recent']),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::Day, 10)->toArray();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('recent', (string) $results[0]->getHashtag());
    }

    public function testTrendingHashtagsRespectsLimit(): void
    {
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'a', new TagCollection([Tag::tryFromArray(['t', 'a'])]));
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'b', new TagCollection([Tag::tryFromArray(['t', 'b'])]));
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'c', new TagCollection([Tag::tryFromArray(['t', 'c'])]));

        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 2)->toArray();

        $this->assertCount(2, $results);
    }

    public function testTrendingHashtagsTreatsTagValuesAsLowercase(): void
    {
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'a', new TagCollection([Tag::tryFromArray(['t', 'Bitcoin'])]));
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'b', new TagCollection([Tag::tryFromArray(['t', 'bitcoin'])]));
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'c', new TagCollection([Tag::tryFromArray(['t', 'BITCOIN'])]));

        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(HashtagCount::class, $results[0]);
        $this->assertSame('bitcoin', (string) $results[0]->getHashtag());
        $this->assertSame(3, $results[0]->getCount());
    }

    public function testMostFollowedUsesDenormalisedTable(): void
    {
        $followed = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEvent(EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::tryFromArray(['p', $followed->getPublicKey()->toHex()]),
        ]));

        $follower2 = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEventWithKey($follower2, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::tryFromArray(['p', $followed->getPublicKey()->toHex()]),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::MostFollowed, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PubkeyCount::class, $results[0]);
        $this->assertSame($followed->getPublicKey()->toHex(), $results[0]->getPubkey()->toHex());
        $this->assertSame(2, $results[0]->getCount());
    }

    public function testMostReactedToCountsReactions(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEvent(EventKind::fromInt(EventKind::REACTION), '+', new TagCollection([
            Tag::tryFromArray(['p', $target->getPublicKey()->toHex()]),
        ]));

        $reactor2 = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEventWithKey($reactor2, EventKind::fromInt(EventKind::REACTION), '+', new TagCollection([
            Tag::tryFromArray(['p', $target->getPublicKey()->toHex()]),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::MostReactedTo, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PubkeyCount::class, $results[0]);
        $this->assertSame($target->getPublicKey()->toHex(), $results[0]->getPubkey()->toHex());
        $this->assertSame(2, $results[0]->getCount());
    }

    public function testMostRepostedCountsReposts(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEvent(EventKind::fromInt(EventKind::REPOST), '{}', new TagCollection([
            Tag::tryFromArray(['p', $target->getPublicKey()->toHex()]),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::MostReposted, ExplorePeriod::All, 10)->toArray();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PubkeyCount::class, $results[0]);
        $this->assertSame($target->getPublicKey()->toHex(), $results[0]->getPubkey()->toHex());
    }

    public function testMostReactedToRespectsTimeWindow(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEventAtTime(EventKind::fromInt(EventKind::REACTION), '+', time() - 90000, new TagCollection([
            Tag::tryFromArray(['p', $target->getPublicKey()->toHex()]),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::MostReactedTo, ExplorePeriod::Day, 10)->toArray();

        $this->assertSame([], $results);
    }

    public function testHashtagCountSerialisesToExpectedShape(): void
    {
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'test', new TagCollection([
            Tag::tryFromArray(['t', 'nostr']),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 1)->toArray();

        $this->assertSame(['hashtag' => 'nostr', 'count' => 1], $results[0]->toArray());
    }

    public function testPubkeyCountSerialisesToExpectedShape(): void
    {
        $followed = KeyPair::generate(SignedEventFactory::signer());
        $this->storeEvent(EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::tryFromArray(['p', $followed->getPublicKey()->toHex()]),
        ]));

        $results = $this->exploreQuery->findByStat(StatName::MostFollowed, ExplorePeriod::All, 1)->toArray();

        $this->assertSame(['pubkey' => $followed->getPublicKey()->toHex(), 'count' => 1], $results[0]->toArray());
    }

    public function testEveryStatNameAndPeriodCombinationReturnsArray(): void
    {
        $this->storeEvent(EventKind::fromInt(EventKind::TEXT_NOTE), 'test', new TagCollection([
            Tag::tryFromArray(['t', 'test']),
        ]));

        foreach (StatName::cases() as $stat) {
            foreach (ExplorePeriod::cases() as $period) {
                $results = $this->exploreQuery->findByStat($stat, $period, 10)->toArray();
                $this->assertLessThanOrEqual(10, count($results), "{$stat->value}/{$period->value} should respect the limit");
            }
        }
    }

    private function storeEvent(EventKind $kind, string $content, ?TagCollection $tags = null): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, $kind, $content, $tags);
        $this->eventStore->store($event);
    }

    private function storeEventAtTime(EventKind $kind, string $content, int $timestamp, ?TagCollection $tags = null): void
    {
        $event = SignedEventFactory::signedEventAtTime($this->keyPair, $kind, $content, $timestamp, $tags);
        $this->eventStore->store($event);
    }

    private function storeEventWithKey(KeyPair $keyPair, EventKind $kind, string $content, ?TagCollection $tags = null): void
    {
        $event = SignedEventFactory::signedEvent($keyPair, $kind, $content, $tags);
        $this->eventStore->store($event);
    }
}
