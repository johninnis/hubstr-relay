<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Collection;

use Innis\Hubstr\Relay\Application\DTO\ActiveSubscriptionSnapshot;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<ActiveSubscriptionSnapshot>
 */
final class ActiveSubscriptionSnapshotCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return ActiveSubscriptionSnapshot::class;
    }
}
