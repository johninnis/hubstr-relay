<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Override;

final readonly class DeleteEventIdsCommand implements WriteCommandInterface
{
    public function __construct(
        private EventIdCollection $eventIds,
        private ActingKeys $deleter,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteByEventIds($this->eventIds, $this->deleter);
    }
}
