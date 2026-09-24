<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

interface EventWriterInterface
{
    public function store(Event $event): EventStoreOutcome;
}
