<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventQueryStoreTest extends TestCase
{
    private const int COUNT_LIMIT = 1000;

    private const int NOW = 1_700_000_000;

    private PDO $pdo;
    private EventWriteStore $writeStore;
    private EventQueryStore $queryStore;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->queryStore = new EventQueryStore($this->pdo);
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());

        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello'));
    }

    public function testNonEmptyAuthorFilterMatches(): void
    {
        $author = $this->keyPair->getPublicKey()->toHex();

        $events = $this->queryStore->findByFilters(new FilterCollection([Filter::tryFromArray(['authors' => [$author]])]));

        $this->assertCount(1, $events);
    }

    public function testEmptyAuthorsMatchesNothing(): void
    {
        $events = $this->queryStore->findByFilters(new FilterCollection([Filter::tryFromArray(['authors' => []])]));

        $this->assertCount(0, $events);
    }

    public function testEmptyKindsMatchesNothing(): void
    {
        $events = $this->queryStore->findByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => []])]));

        $this->assertCount(0, $events);
    }

    public function testEmptyIdsMatchesNothing(): void
    {
        $events = $this->queryStore->findByFilters(new FilterCollection([Filter::tryFromArray(['ids' => []])]));

        $this->assertCount(0, $events);
    }

    public function testCountWithEmptyAuthorsIsZero(): void
    {
        $this->assertSame(0, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['authors' => []])]), self::COUNT_LIMIT)->toInt());
    }

    public function testACountBelowTheCeilingIsExact(): void
    {
        $count = $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), 10);

        $this->assertEquals(EventCount::exact(1), $count);
    }

    public function testACountStopsAtTheCeilingAndIsReportedApproximate(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'three'));

        $count = $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), 2);

        $this->assertEquals(EventCount::approximate(2), $count);
    }

    public function testACountIsApproximateWhenAnyFilterReachesTheCeiling(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+'));

        $count = $this->queryStore->countByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1]]),
            Filter::tryFromArray(['kinds' => [7]]),
        ]), 2);

        $this->assertEquals(EventCount::approximate(2), $count);
    }

    public function testAnEventMatchingTwoFiltersIsCountedOnce(): void
    {
        $count = $this->queryStore->countByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1]]),
            Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()]]),
        ]), self::COUNT_LIMIT);

        $this->assertEquals(EventCount::exact(1), $count);
    }

    public function testACountAgreesWithWhatTheSameFiltersWouldReturn(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+'));

        $filters = new FilterCollection([
            Filter::tryFromArray(['kinds' => [1]]),
            Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()]]),
        ]);

        $this->assertSame(
            count($this->queryStore->findByFilters($filters)),
            $this->queryStore->countByFilters($filters, self::COUNT_LIMIT)->toInt(),
        );
    }

    public function testMultipleFiltersReturnGloballyOrderedResults(): void
    {
        $oldest = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'oldest', 100);
        $middle = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(2), 'middle', 200);
        $newest = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'newest', 300);
        $this->writeStore->store($oldest);
        $this->writeStore->store($middle);
        $this->writeStore->store($newest);

        $events = $this->queryStore->findByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1], 'until' => 1000]),
            Filter::tryFromArray(['kinds' => [2], 'until' => 1000]),
        ]));

        self::assertSame([$newest->toJson(), $middle->toJson(), $oldest->toJson()], self::encodings($events));
    }

    public function testOverlappingFiltersDeduplicateAndHonourTheGlobalLimit(): void
    {
        $first = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'first', 100);
        $second = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'second', 200);
        $this->writeStore->store($first);
        $this->writeStore->store($second);

        $author = $this->keyPair->getPublicKey()->toHex();
        $events = $this->queryStore->findByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1], 'until' => 1000]),
            Filter::tryFromArray(['authors' => [$author], 'until' => 1000]),
        ]));

        self::assertSame([$second->toJson(), $first->toJson()], self::encodings($events));
    }

    public function testAStoredEventCarriesItsHeaderFromTheIndexedColumns(): void
    {
        $reaction = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+');
        $this->writeStore->store($reaction);

        $header = $this->onlyStored(['kinds' => [EventKind::REACTION]])->getHeader();

        self::assertEquals(EventHeader::of($reaction), $header);
    }

    public function testAStoredEventCarriesTheBytesTheStoreWrote(): void
    {
        $reaction = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+');
        $this->writeStore->store($reaction);

        self::assertSame($reaction->toJson(), $this->onlyStored(['kinds' => [EventKind::REACTION]])->getEncoded()->toJson());
    }

    /**
     * @param list<string> $expiries
     */
    #[DataProvider('statedExpiries')]
    public function testAStoredEventIsExpiredExactlyWhenTheEventItHoldsIs(array $expiries): void
    {
        $event = SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::REACTION),
            '+',
            new TagCollection(array_map(static fn (string $value): ?Tag => Tag::tryFromArray(['expiration', $value]), $expiries)),
        );
        $this->writeStore->store($event);
        $now = Timestamp::fromInt(self::NOW);

        self::assertSame($event->isExpiredAt($now), $this->onlyStored(['kinds' => [EventKind::REACTION]])->isExpiredAt($now));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function statedExpiries(): iterable
    {
        yield 'none' => [[]];
        yield 'passed' => [[(string) (self::NOW - 1)]];
        yield 'in the future' => [[(string) (self::NOW + 1)]];
        yield 'future then passed' => [[(string) (self::NOW + 1), (string) (self::NOW - 1)]];
        yield 'unparseable beside passed' => [['soon', (string) (self::NOW - 1)]];
        yield 'leading zero' => [['0'.(self::NOW - 1)]];
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function onlyStored(array $filter): StoredEvent
    {
        $stored = $this->queryStore->findByFilters(new FilterCollection([Filter::tryFromArray($filter)]))->toArray();

        self::assertCount(1, $stored);

        return $stored[0];
    }

    /**
     * @return list<string>
     */
    private static function encodings(StoredEventCollection $events): array
    {
        return array_map(static fn (StoredEvent $event): string => $event->getEncoded()->toJson(), $events->toArray());
    }
}
