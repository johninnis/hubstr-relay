<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Countable;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Override;

final readonly class StoreEventsCommand implements WriteCommandInterface, Countable
{
    public function __construct(
        private EventCollection $events,
    ) {
    }

    #[Override]
    public function count(): int
    {
        return $this->events->count();
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->storeBatch($this->events);
    }
}
