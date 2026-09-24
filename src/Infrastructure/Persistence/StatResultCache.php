<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;

// Deliberate: a hit is read on the main connection, a miss is persisted through the write worker — see ADR-0009
final readonly class StatResultCache
{
    public function __construct(
        private StatResultsStore $store,
        private WriteCoordinator $writeCoordinator,
    ) {
    }

    public function find(StatKey $key): ?string
    {
        return $this->store->find($key)?->getPayload();
    }

    public function save(StatKey $key, string $payload): void
    {
        $this->writeCoordinator->apply(new PersistStatResultCommand($key, $payload));
    }
}
