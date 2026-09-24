<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\ExploreQueryInterface;
use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchExploreStatQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Override;

final readonly class WorkerExploreQuery implements ExploreQueryInterface
{
    public function __construct(
        private ReadWorkerPool $pool,
    ) {
    }

    #[Override]
    public function findByStat(StatName $stat, ExplorePeriod $period, int $limit): ExploreEntryCollection
    {
        return $this->pool->queryForInstance(
            new FetchExploreStatQuery($stat, $period, $limit),
            ExploreEntryCollection::class,
        );
    }
}
