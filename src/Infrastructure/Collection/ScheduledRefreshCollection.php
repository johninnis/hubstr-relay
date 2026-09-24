<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Collection;

use Innis\Hubstr\Relay\Infrastructure\Stats\ScheduledRefresh;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<ScheduledRefresh>
 */
final class ScheduledRefreshCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return ScheduledRefresh::class;
    }
}
