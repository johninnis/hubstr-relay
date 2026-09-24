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

final readonly class WriteContext
{
    public function __construct(
        private EventWriteStore $eventWriteStore,
        private PolicyWriteStore $policyWriteStore,
        private StatResultsStore $statResultsStore,
        private ClockInterface $clock,
    ) {
    }

    public static function forConnection(PDO $pdo): self
    {
        $clock = new SystemClock();
        $statements = new StatementRunner($pdo);

        return new self(
            new EventWriteStore($pdo, $statements, new Denormaliser($statements)),
            new PolicyWriteStore($pdo, $clock),
            new StatResultsStore($statements),
            $clock,
        );
    }

    public function getEventWriteStore(): EventWriteStore
    {
        return $this->eventWriteStore;
    }

    public function getPolicyWriteStore(): PolicyWriteStore
    {
        return $this->policyWriteStore;
    }

    public function getStatResultsStore(): StatResultsStore
    {
        return $this->statResultsStore;
    }

    public function getClock(): ClockInterface
    {
        return $this->clock;
    }
}
