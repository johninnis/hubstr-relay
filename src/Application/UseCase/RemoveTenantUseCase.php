<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class RemoveTenantUseCase
{
    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
    ) {
    }

    // Deliberate: the outcome is read after the write, never predicted before it — see ADR-0021
    public function remove(PublicKey $pubkey): ?TenantPolicyFailure
    {
        if ($this->policyManagement->removeTenantUnlessLast($pubkey)) {
            return null;
        }

        return $this->policyState->isTenantPubkey($pubkey) ? TenantPolicyFailure::LastTenant : null;
    }
}
