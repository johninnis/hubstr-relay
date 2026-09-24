<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Infrastructure\Collection\ScheduledRefreshCollection;

final readonly class StatsRefreshSchedule
{
    public static function forDatabase(SqliteDatabase $database): ScheduledRefreshCollection
    {
        $refreshes = [new ScheduledRefresh('totals', new RefreshTotalsTask($database))];

        foreach (ExplorePeriod::cases() as $period) {
            foreach (StatName::cases() as $stat) {
                $refreshes[] = new ScheduledRefresh(
                    "{$stat->value}/{$period->value}",
                    new RefreshExploreStatTask($database, $stat, $period),
                );
            }
        }

        return new ScheduledRefreshCollection($refreshes);
    }
}
