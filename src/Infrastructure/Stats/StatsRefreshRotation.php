<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Innis\Hubstr\Relay\Infrastructure\Collection\ScheduledRefreshCollection;
use InvalidArgumentException;

final class StatsRefreshRotation
{
    private const float REFRESH_WINDOW_SECONDS = 600.0;

    private int $cursor = 0;

    /** @var list<ScheduledRefresh> */
    private readonly array $rotation;

    public function __construct(ScheduledRefreshCollection $schedule)
    {
        if ($schedule->isEmpty()) {
            throw new InvalidArgumentException('Stats refresh scheduler requires at least one scheduled refresh');
        }

        $this->rotation = $schedule->toArray();
    }

    public function next(): ScheduledRefresh
    {
        $scheduled = $this->rotation[$this->cursor];
        $this->cursor = ($this->cursor + 1) % count($this->rotation);

        return $scheduled;
    }

    public function tickInterval(): float
    {
        return self::REFRESH_WINDOW_SECONDS / count($this->rotation);
    }
}
