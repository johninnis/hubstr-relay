<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteCoordinatesCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteEventIdsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
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
    public function findByFilters(FilterCollection $filters): EventCollection
    {
        $events = [];

        foreach ($this->readPool->queryForList(new FindByFiltersQuery($filters)) as $rawEvent) {
            if (!is_string($rawEvent)) {
                throw new WorkerResultException('Event store worker returned a non-string stored event');
            }

            $events[] = Event::tryFromJson($rawEvent)
                ?? throw new WorkerResultException('Event store worker returned an unparseable stored event');
        }

        return new EventCollection($events);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): EventCount
    {
        return $this->readPool->queryForInstance(new CountByFiltersQuery($filters, $this->countLimit), EventCount::class);
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        return $this->writeCoordinator->applyForInt(new DeleteEventIdsCommand($eventIds, $author));
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        return $this->writeCoordinator->applyForInt(new DeleteCoordinatesCommand($coordinates, $author));
    }
}
