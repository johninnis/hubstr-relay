<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\DTO;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use stdClass;

final readonly class SubscriptionSnapshot
{
    public function __construct(
        private SubscriptionId $id,
        private SubscriptionState $state,
        private FilterCollection $filters,
    ) {
    }

    /**
     * @return array{id: string, state: string, filters: list<array<string, mixed>|stdClass>}
     */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'state' => $this->state->value,
            'filters' => $this->filters->toJsonArray(),
        ];
    }
}
