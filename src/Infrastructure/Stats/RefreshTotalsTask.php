<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Override;

/**
 * @implements Task<PersistStatResultCommand, mixed, mixed>
 */
final readonly class RefreshTotalsTask implements Task
{
    public function __construct(
        private SqliteDatabase $database,
    ) {
    }

    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $pdo = $this->database->connect();
        $payload = new StatPayloadCodec()->encodeTotals(new SqliteStatsProvider($pdo)->getStats());

        return new PersistStatResultCommand(StatKey::totals(), $payload);
    }
}
