<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

final readonly class UpdateRateLimitsUseCase
{
    public function __construct(
        private PolicyStateInterface $policyState,
        private PolicyManagementInterface $policyManagement,
    ) {
    }

    /**
     * @param array<array-key, mixed> $patch
     */
    public function patch(array $patch): ?RateLimitConfig
    {
        $merged = RateLimitConfig::tryFromArray([...$this->policyState->getRateLimits()->toArray(), ...$patch]);

        if (null === $merged) {
            return null;
        }

        $this->policyManagement->setRateLimits($merged);

        return $merged;
    }
}
