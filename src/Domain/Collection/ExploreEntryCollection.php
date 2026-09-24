<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<ExploreEntryInterface>
 */
final class ExploreEntryCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return ExploreEntryInterface::class;
    }
}
