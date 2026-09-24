<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\DTO;

use Innis\Hubstr\Relay\Application\Collection\SubscriptionSnapshotCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;

final readonly class ClientSnapshot
{
    public function __construct(
        private RelayClient $client,
        private SessionCounters $counters,
        private SubscriptionSnapshotCollection $subscriptions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $connection = $this->client->getConnectionInfo();

        return [
            'id' => (string) $this->client->getId(),
            'ip' => (string) $connection->getIpAddress(),
            'user_agent' => $connection->getUserAgent(),
            'connected_at' => $connection->getConnectedAt()->toInt(),
            'events_received' => $this->counters->getEventsReceived(),
            'events_accepted' => $this->counters->getEventsAccepted(),
            'events_sent' => $this->counters->getEventsSent(),
            'subscriptions' => array_map(
                static fn (SubscriptionSnapshot $subscription): array => $subscription->toArray(),
                $this->subscriptions->toArray(),
            ),
        ];
    }
}
