<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;

final readonly class StatResultsStore
{
    public const int CACHE_LIMIT = 100;

    public function __construct(
        private StatementRunner $statements,
    ) {
    }

    public function find(StatKey $key): ?CachedStatResult
    {
        $row = $this->statements->selectRow(
            'SELECT computed_at, payload FROM stat_results WHERE stat_name = ? AND period = ?',
            [$key->getName(), $key->getPeriod()->value],
        );
        if (null === $row) {
            return null;
        }

        $computedAt = $row['computed_at'] ?? null;
        $payload = $row['payload'] ?? null;
        if (!is_numeric($computedAt) || !is_string($payload)) {
            return null;
        }

        return new CachedStatResult((int) $computedAt, $payload);
    }

    public function save(StatKey $key, CachedStatResult $result): void
    {
        $this->statements->execute(
            'INSERT INTO stat_results (stat_name, period, computed_at, payload) VALUES (?, ?, ?, ?)
             ON CONFLICT(stat_name, period) DO UPDATE SET computed_at = excluded.computed_at, payload = excluded.payload',
            [$key->getName(), $key->getPeriod()->value, $result->getComputedAt(), $result->getPayload()],
        );
    }
}
