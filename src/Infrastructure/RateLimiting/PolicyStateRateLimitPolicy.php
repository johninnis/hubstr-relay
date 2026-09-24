<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\RateLimiting;

use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Override;

final readonly class PolicyStateRateLimitPolicy implements RateLimitPolicyInterface
{
    public function __construct(
        private PolicyStateInterface $policyState,
    ) {
    }

    #[Override]
    public function limitFor(RateLimitMetric $metric): int
    {
        return $this->policyState->getRateLimits()->perMinute($metric);
    }
}
