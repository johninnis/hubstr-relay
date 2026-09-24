<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Collection;

use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<ClientSnapshot>
 */
final class ClientSnapshotCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return ClientSnapshot::class;
    }
}
