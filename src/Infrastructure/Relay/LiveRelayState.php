<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Relay;

use Innis\Hubstr\Relay\Application\Collection\ActiveSubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\Collection\ClientSnapshotCollection;
use Innis\Hubstr\Relay\Application\Collection\SubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\DTO\ActiveSubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Hubstr\Relay\Application\DTO\SubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\Port\RelayStateInterface;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Infrastructure\Server\RelayInstance;
use Override;

final readonly class LiveRelayState implements RelayStateInterface
{
    public function __construct(
        private RelayInstance $relay,
    ) {
    }

    #[Override]
    public function getConnectedClients(): ClientSnapshotCollection
    {
        return new ClientSnapshotCollection(array_map(
            $this->snapshotClient(...),
            $this->relay->getClients()->toArray(),
        ));
    }

    #[Override]
    public function getConnectedClient(ClientId $id): ?ClientSnapshot
    {
        $client = $this->relay->getClients()->get($id);

        return null === $client ? null : $this->snapshotClient($client);
    }

    #[Override]
    public function getActiveSubscriptions(): ActiveSubscriptionSnapshotCollection
    {
        $subscriptions = [];

        foreach ($this->relay->getClients() as $client) {
            foreach ($this->relay->getSubscriptionsForClient($client->getId()) as $subscription) {
                $subscriptions[] = new ActiveSubscriptionSnapshot($client, self::snapshotSubscription($subscription));
            }
        }

        return new ActiveSubscriptionSnapshotCollection($subscriptions);
    }

    private function snapshotClient(RelayClient $client): ClientSnapshot
    {
        return new ClientSnapshot(
            $client,
            $this->relay->getSessionCounters($client->getId()),
            new SubscriptionSnapshotCollection(array_map(
                self::snapshotSubscription(...),
                $this->relay->getSubscriptionsForClient($client->getId())->toArray(),
            )),
        );
    }

    private static function snapshotSubscription(Subscription $subscription): SubscriptionSnapshot
    {
        return new SubscriptionSnapshot(
            $subscription->getId(),
            $subscription->getState(),
            $subscription->getFilters(),
        );
    }
}
