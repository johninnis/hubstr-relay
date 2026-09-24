<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Hubstr\Relay\Application\DTO\ActiveSubscriptionSnapshot;
use Innis\Hubstr\Relay\Application\DTO\ClientSnapshot;
use Innis\Hubstr\Relay\Application\Port\RelayStateInterface;
use Innis\Hubstr\Relay\Domain\Enum\HubstrRpcMethod;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Override;

final readonly class RelayStateRpcHandler implements RpcMethodHandlerInterface
{
    public function __construct(
        private RelayStateInterface $relayState,
    ) {
    }

    #[Override]
    public function handlers(): array
    {
        return [
            HubstrRpcMethod::ListConnections->value => $this->listConnections(...),
            HubstrRpcMethod::GetConnection->value => $this->getConnection(...),
            HubstrRpcMethod::ListSubscriptions->value => $this->listSubscriptions(...),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listConnections(): array
    {
        return array_map(
            static fn (ClientSnapshot $client): array => $client->toArray(),
            $this->relayState->getConnectedClients()->toArray(),
        );
    }

    /**
     * @return array<string, mixed>|RpcRejection|null
     */
    private function getConnection(RpcParams $params): array|RpcRejection|null
    {
        $id = $params->nonEmptyString(0);

        if (null === $id) {
            return RpcRejection::badRequest('Missing connection id');
        }

        return $this->relayState->getConnectedClient(ClientId::fromString($id))?->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSubscriptions(): array
    {
        return array_map(
            static fn (ActiveSubscriptionSnapshot $subscription): array => $subscription->toArray(),
            $this->relayState->getActiveSubscriptions()->toArray(),
        );
    }
}
