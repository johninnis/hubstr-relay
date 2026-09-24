<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatResultsStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Override;

/**
 * @implements Task<PersistStatResultCommand, mixed, mixed>
 */
final readonly class RefreshExploreStatTask implements Task
{
    public function __construct(
        private SqliteDatabase $database,
        private StatName $stat,
        private ExplorePeriod $period,
    ) {
    }

    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $pdo = $this->database->connect();
        $entries = new SqliteExploreQuery($pdo)->findByStat($this->stat, $this->period, StatResultsStore::CACHE_LIMIT)->toArray();
        $payload = new StatPayloadCodec()->encodeEntries($entries);

        return new PersistStatResultCommand(StatKey::forStat($this->stat, $this->period), $payload);
    }
}
