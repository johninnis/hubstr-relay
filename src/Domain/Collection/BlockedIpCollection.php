<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<BlockedIp>
 */
final class BlockedIpCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return BlockedIp::class;
    }
}
