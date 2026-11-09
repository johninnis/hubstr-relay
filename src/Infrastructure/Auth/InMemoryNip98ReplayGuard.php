<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Auth;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Application\Port\Nip98ReplayGuardInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Override;

final class InMemoryNip98ReplayGuard implements Nip98ReplayGuardInterface
{
    private const int DEFAULT_MAX_ENTRIES = 100_000;

    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
    }

    #[Override]
    public function recordOnce(EventId $eventId, int $ttlSeconds): bool
    {
        $now = $this->clock->now()->toInt();
        $this->prune($now);

        $key = $eventId->toHex();
        if (isset($this->seen[$key])) {
            return false;
        }

        // Deliberate: a full guard refuses, as evicting a live entry would make that event replayable — see ADR-0047
        if (count($this->seen) >= $this->maxEntries) {
            return false;
        }

        $this->seen[$key] = $now + $ttlSeconds;

        return true;
    }

    private function prune(int $now): void
    {
        while ([] !== $this->seen) {
            $firstKey = array_key_first($this->seen);
            if ($this->seen[$firstKey] > $now) {
                return;
            }
            unset($this->seen[$firstKey]);
        }
    }
}
