<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerEventStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Support\ActingKeysMother;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class WorkerEventStoreTest extends TestCase
{
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testStoreRoutesThroughWriteCoordinator(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $readChannel = new QueueChannel();
        $store = new WorkerEventStore(
            new WriteCoordinator(new QueueChannel([[EventStoreOutcome::Stored]])),
            new ReadWorkerPool([$readChannel]),
            100,
        );

        $outcome = async(static fn () => $store->store($event))->await();

        self::assertSame(EventStoreOutcome::Stored, $outcome);
        self::assertSame([], $readChannel->sent);
    }

    public function testDeleteByEventIdsRoutesThroughWriteCoordinator(): void
    {
        $store = new WorkerEventStore(
            new WriteCoordinator(new QueueChannel([3])),
            new ReadWorkerPool([new QueueChannel()]),
            100,
        );

        $deleted = async(static fn () => $store->deleteByEventIds(new EventIdCollection([]), ActingKeysMother::of(SignedEventFactory::pubkey('aa'))))->await();

        self::assertSame(3, $deleted);
    }

    public function testDeleteByCoordinatesRoutesThroughWriteCoordinator(): void
    {
        $store = new WorkerEventStore(
            new WriteCoordinator(new QueueChannel([0])),
            new ReadWorkerPool([new QueueChannel()]),
            100,
        );

        $deleted = async(static fn () => $store->deleteByCoordinates(new EventCoordinateCollection([]), SignedEventFactory::pubkey('aa'), Timestamp::fromInt(0)))->await();

        self::assertSame(0, $deleted);
    }

    public function testFindByFiltersAnswersWhatTheReadPoolReturns(): void
    {
        $stored = $this->storedNote();

        self::assertSame($stored, self::storeReadingFrom(new QueueChannel([$stored]))->findByFilters(self::textNotes(), ActingKeysMother::anonymous()));
    }

    public function testFindByFiltersSendsAFindByFiltersQueryToTheReadPool(): void
    {
        $readChannel = new QueueChannel([$this->storedNote()]);
        self::storeReadingFrom($readChannel)->findByFilters(self::textNotes(), ActingKeysMother::anonymous());

        self::assertInstanceOf(FindByFiltersQuery::class, $readChannel->sent[0]);
    }

    public function testCountByFiltersAnswersWhatTheReadPoolReturns(): void
    {
        self::assertEquals(EventCount::approximate(7), self::storeReadingFrom(new QueueChannel([EventCount::approximate(7)]))->countByFilters(self::textNotes(), ActingKeysMother::anonymous()));
    }

    public function testCountByFiltersSendsACountByFiltersQueryToTheReadPool(): void
    {
        $readChannel = new QueueChannel([EventCount::approximate(7)]);
        self::storeReadingFrom($readChannel)->countByFilters(self::textNotes(), ActingKeysMother::anonymous());

        self::assertInstanceOf(CountByFiltersQuery::class, $readChannel->sent[0]);
    }

    private function storedNote(): StoredEventCollection
    {
        return new StoredEventCollection([StoredEvent::of(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello'))]);
    }

    private static function storeReadingFrom(QueueChannel $readChannel): WorkerEventStore
    {
        return new WorkerEventStore(
            new WriteCoordinator(new QueueChannel()),
            new ReadWorkerPool([$readChannel]),
            100,
        );
    }

    private static function textNotes(): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]);
    }

    public function testFindByFiltersThrowsWhenTheWorkerResultIsNotStoredEvents(): void
    {
        $store = new WorkerEventStore(
            new WriteCoordinator(new QueueChannel()),
            new ReadWorkerPool([new QueueChannel(['unexpected'])]),
            100,
        );

        $this->expectException(WorkerResultException::class);

        $store->findByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), ActingKeysMother::anonymous());
    }

    public function testCountByFiltersThrowsWhenTheWorkerResultIsNotAnEventCount(): void
    {
        $store = new WorkerEventStore(
            new WriteCoordinator(new QueueChannel()),
            new ReadWorkerPool([new QueueChannel(['unexpected'])]),
            100,
        );

        $this->expectException(WorkerResultException::class);

        $store->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), ActingKeysMother::anonymous());
    }
}
