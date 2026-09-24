<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class DeleteCoordinatesCommand implements WriteCommandInterface
{
    public function __construct(
        private EventCoordinateCollection $coordinates,
        private PublicKey $author,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteByCoordinates($this->coordinates, $this->author);
    }
}
