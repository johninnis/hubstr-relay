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
final readonly class WriteWorkerTask implements Task
{
    public function __construct(
        private SqliteDatabase $database,
    ) {
    }

    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $context = WriteContext::forConnection($this->database->connect());

        new WorkerMessageLoop($channel, $cancellation)->process(
            WriteCommandInterface::class,
            static fn (WriteCommandInterface $command): mixed => $command->applyTo($context),
        );

        return null;
    }
}
