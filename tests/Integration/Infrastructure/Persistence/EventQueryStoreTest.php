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
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use PDO;
use PHPUnit\Framework\TestCase;

final class EventQueryStoreTest extends TestCase
{
    private const int COUNT_LIMIT = 1000;

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

        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([Filter::tryFromArray(['authors' => [$author]])]));

        $this->assertCount(1, $events);
    }

    public function testEmptyAuthorsMatchesNothing(): void
    {
        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([Filter::tryFromArray(['authors' => []])]));

        $this->assertSame([], $events);
    }

    public function testEmptyKindsMatchesNothing(): void
    {
        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => []])]));

        $this->assertSame([], $events);
    }

    public function testEmptyIdsMatchesNothing(): void
    {
        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([Filter::tryFromArray(['ids' => []])]));

        $this->assertSame([], $events);
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
            count($this->queryStore->findRawJsonByFilters($filters)),
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

        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1], 'until' => 1000]),
            Filter::tryFromArray(['kinds' => [2], 'until' => 1000]),
        ]));

        self::assertSame([$newest->toJson(), $middle->toJson(), $oldest->toJson()], $events);
    }

    public function testOverlappingFiltersDeduplicateAndHonourTheGlobalLimit(): void
    {
        $first = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'first', 100);
        $second = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'second', 200);
        $this->writeStore->store($first);
        $this->writeStore->store($second);

        $author = $this->keyPair->getPublicKey()->toHex();
        $events = $this->queryStore->findRawJsonByFilters(new FilterCollection([
            Filter::tryFromArray(['kinds' => [1], 'until' => 1000]),
            Filter::tryFromArray(['authors' => [$author], 'until' => 1000]),
        ]));

        self::assertSame([$second->toJson(), $first->toJson()], $events);
    }
}
