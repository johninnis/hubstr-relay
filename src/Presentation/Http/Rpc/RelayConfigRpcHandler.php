<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Http\Rpc;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\ChangeRelayMetadataUseCase;
use Innis\Hubstr\Relay\Application\UseCase\UpdateRateLimitsUseCase;
use Innis\Hubstr\Relay\Domain\Enum\HubstrRpcMethod;
use Innis\Hubstr\Relay\Domain\Failure\MetadataFailure;
use Innis\Nostr\Core\Domain\Enum\Nip86Method;
use Override;

final readonly class RelayConfigRpcHandler implements RpcMethodHandlerInterface
{
    public function __construct(
        private PolicyStateInterface $policyState,
        private ChangeRelayMetadataUseCase $changeMetadata,
        private UpdateRateLimitsUseCase $updateRateLimits,
    ) {
    }

    #[Override]
    public function handlers(): array
    {
        return [
            Nip86Method::ChangeRelayName->value => $this->changeRelayName(...),
            Nip86Method::ChangeRelayDescription->value => $this->changeRelayDescription(...),
            Nip86Method::ChangeRelayIcon->value => $this->changeRelayIcon(...),
            HubstrRpcMethod::GetRateLimits->value => $this->getRateLimits(...),
            HubstrRpcMethod::SetRateLimits->value => $this->setRateLimits(...),
        ];
    }

    private function changeRelayName(RpcParams $params): true|RpcRejection
    {
        return self::appliedOr($this->changeMetadata->changeName($params->string(0) ?? ''));
    }

    private function changeRelayDescription(RpcParams $params): true|RpcRejection
    {
        return self::appliedOr($this->changeMetadata->changeDescription($params->string(0) ?? ''));
    }

    private function changeRelayIcon(RpcParams $params): true|RpcRejection
    {
        return self::appliedOr($this->changeMetadata->changeIcon($params->string(0) ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRateLimits(): array
    {
        return $this->policyState->getRateLimits()->toArray();
    }

    private function setRateLimits(RpcParams $params): true|RpcRejection
    {
        return null === $this->updateRateLimits->patch($params->nested(0)->toArray())
            ? RpcRejection::badRequest('rate limits must be positive integers')
            : true;
    }

    private static function appliedOr(?MetadataFailure $failure): true|RpcRejection
    {
        return null === $failure ? true : RpcRejection::badRequest($failure->value);
    }
}
