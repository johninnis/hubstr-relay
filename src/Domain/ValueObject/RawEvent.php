<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;

final readonly class RawEvent
{
    public function __construct(
        private EventId $id,
        private string $json,
    ) {
    }

    public function getId(): EventId
    {
        return $this->id;
    }

    public function getJson(): string
    {
        return $this->json;
    }
}
