<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Innis\Hubstr\Relay\Infrastructure\Persistence\Denormaliser;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatementRunner;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use PDO;

final class WriteContext
{
    private ?StatementRunner $statements = null;
    private ?EventWriteStore $eventWriteStore = null;
    private ?PolicyWriteStore $policyWriteStore = null;
    private ?StatResultsStore $statResultsStore = null;

    private function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function forConnection(PDO $pdo, ClockInterface $clock = new SystemClock()): self
    {
        return new self($pdo, $clock);
    }

    public function getEventWriteStore(): EventWriteStore
    {
        return $this->eventWriteStore ??= new EventWriteStore($this->pdo, $this->statements(), new Denormaliser($this->statements()));
    }

    public function getPolicyWriteStore(): PolicyWriteStore
    {
        return $this->policyWriteStore ??= new PolicyWriteStore($this->pdo, $this->clock);
    }

    public function getStatResultsStore(): StatResultsStore
    {
        return $this->statResultsStore ??= new StatResultsStore($this->statements());
    }

    public function getClock(): ClockInterface
    {
        return $this->clock;
    }

    private function statements(): StatementRunner
    {
        // Deliberate: one runner per connection, shared by the stores this context vends, so a prepared statement is cached once for the life of the connection — see ADR-0020
        return $this->statements ??= new StatementRunner($this->pdo);
    }
}
