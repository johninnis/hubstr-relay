<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Override;

/**
 * @implements Task<null, mixed, mixed>
 */
final readonly class ReadWorkerTask implements Task
{
    public function __construct(
        private SqliteDatabase $database,
    ) {
    }

    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $context = ReadContext::forConnection($this->database->connect());

        new WorkerMessageLoop($channel, $cancellation)->process(
            ReadQueryInterface::class,
            static fn (ReadQueryInterface $query): mixed => $query->applyTo($context),
        );

        return null;
    }
}
