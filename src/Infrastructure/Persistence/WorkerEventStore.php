<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteCoordinatesCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteEventIdsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Override;

final readonly class WorkerEventStore implements RelayEventStoreInterface
{
    public function __construct(
        private WriteCoordinator $writeCoordinator,
        private ReadWorkerPool $readPool,
        private int $countLimit,
    ) {
    }

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        return $this->writeCoordinator->store($event);
    }

    #[Override]
    public function findByFilters(FilterCollection $filters, ActingKeys $reader): StoredEventCollection
    {
        return $this->readPool->queryForInstance(new FindByFiltersQuery($filters, $reader), StoredEventCollection::class);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters, ActingKeys $reader): EventCount
    {
        return $this->readPool->queryForInstance(new CountByFiltersQuery($filters, $reader, $this->countLimit), EventCount::class);
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, ActingKeys $deleter): int
    {
        return $this->writeCoordinator->applyForInt(new DeleteEventIdsCommand($eventIds, $deleter));
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author, Timestamp $until): int
    {
        return $this->writeCoordinator->applyForInt(new DeleteCoordinatesCommand($coordinates, $author, $until));
    }
}
