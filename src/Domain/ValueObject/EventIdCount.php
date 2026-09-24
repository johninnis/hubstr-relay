<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Override;

final readonly class EventIdCount implements ExploreEntryInterface
{
    public function __construct(
        private EventId $eventId,
        private int $count,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $hex = JsonWireFormat::stringField($data, 'event_id');
        $eventId = null === $hex ? null : EventId::tryFromHex($hex);
        $count = JsonWireFormat::intField($data, 'count');

        return null === $eventId || null === $count ? null : new self($eventId, $count);
    }

    public function getEventId(): EventId
    {
        return $this->eventId;
    }

    #[Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId->toHex(),
            'count' => $this->count,
        ];
    }
}
