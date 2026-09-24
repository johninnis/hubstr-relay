<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;

interface ExploreQueryInterface
{
    public function findByStat(StatName $stat, ExplorePeriod $period, int $limit): ExploreEntryCollection;
}
