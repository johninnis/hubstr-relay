<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Query;

use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Override;

final readonly class FetchTotalsQuery implements ReadQueryInterface
{
    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        return $context->getStatsProvider()->getStats();
    }
}
