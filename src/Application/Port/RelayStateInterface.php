<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Application\Collection\ActiveSubscriptionSnapshotCollection;
use Innis\Hubstr\Relay\Application\Collection\ClientSnapshotCollection;
use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface RelayStateInterface
{
    public function getConnectedClients(): ClientSnapshotCollection;

    public function getConnectedClient(ClientId $id): ?ClientSnapshot;

    public function getActiveSubscriptions(): ActiveSubscriptionSnapshotCollection;
}
