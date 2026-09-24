<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Query;

use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Override;

final readonly class FetchExploreStatQuery implements ReadQueryInterface
{
    public function __construct(
        private StatName $stat,
        private ExplorePeriod $period,
        private int $limit,
    ) {
    }

    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        return $context->getExploreQuery()->findByStat($this->stat, $this->period, $this->limit);
    }
}
