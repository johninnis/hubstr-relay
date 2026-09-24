<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\DTO;

use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use stdClass;

final readonly class ActiveSubscriptionSnapshot
{
    public function __construct(
        private RelayClient $client,
        private SubscriptionSnapshot $subscription,
    ) {
    }

    /**
     * @return array{client_id: string, client_ip: string, subscription_id: string, state: string, filters: list<array<string, mixed>|stdClass>}
     */
    public function toArray(): array
    {
        $subscription = $this->subscription->toArray();

        return [
            'client_id' => (string) $this->client->getId(),
            'client_ip' => (string) $this->client->getConnectionInfo()->getIpAddress(),
            'subscription_id' => $subscription['id'],
            'state' => $subscription['state'],
            'filters' => $subscription['filters'],
        ];
    }
}
