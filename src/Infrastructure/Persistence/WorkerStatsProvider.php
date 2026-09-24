<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\StatsProviderInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchTotalsQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Override;

final readonly class WorkerStatsProvider implements StatsProviderInterface
{
    public function __construct(
        private ReadWorkerPool $pool,
    ) {
    }

    #[Override]
    public function getStats(): StatTotals
    {
        return $this->pool->queryForInstance(new FetchTotalsQuery(), StatTotals::class);
    }
}
