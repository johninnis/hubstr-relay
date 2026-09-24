<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Support;

use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;

final readonly class HostileSessionChurnOutcome
{
    /**
     * @param list<string> $escapedFaults
     */
    public function __construct(
        private array $escapedFaults,
        private int $internalErrorNotices,
        private int $mostClientsRegistered,
        private int $clientsLeft,
        private int $subscriptionsLeft,
        private RelayMetrics $metricsAfterTeardown,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getEscapedFaults(): array
    {
        return $this->escapedFaults;
    }

    public function getInternalErrorNotices(): int
    {
        return $this->internalErrorNotices;
    }

    public function getMostClientsRegistered(): int
    {
        return $this->mostClientsRegistered;
    }

    public function getClientsLeft(): int
    {
        return $this->clientsLeft;
    }

    public function getSubscriptionsLeft(): int
    {
        return $this->subscriptionsLeft;
    }

    public function getMetricsAfterTeardown(): RelayMetrics
    {
        return $this->metricsAfterTeardown;
    }
}
