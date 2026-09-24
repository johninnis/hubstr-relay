<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Hubstr\Relay\Application\Port\StatsProviderInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Hubstr\Relay\Tests\Support\StatTotalsMother;
use Override;

final class RecordingStatsProvider implements StatsProviderInterface
{
    public StatTotals $stats;
    public int $callCount = 0;

    public function __construct()
    {
        $this->stats = StatTotalsMother::withEvents(0);
    }

    #[Override]
    public function getStats(): StatTotals
    {
        ++$this->callCount;

        return $this->stats;
    }
}
