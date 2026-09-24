<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;

interface StatsProviderInterface
{
    public function getStats(): StatTotals;
}
