<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Collection;

use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<LifecycleInterface>
 */
final class LifecycleCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return LifecycleInterface::class;
    }
}
