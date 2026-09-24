<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Collection;

use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<SubscriptionSnapshot>
 */
final class SubscriptionSnapshotCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return SubscriptionSnapshot::class;
    }
}
