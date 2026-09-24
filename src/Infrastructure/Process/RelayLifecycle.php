<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Process;

use Amp\Parallel\Worker\WorkerPool;
use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Relay\Infrastructure\Collection\LifecycleCollection;
use Override;

final readonly class RelayLifecycle implements LifecycleInterface
{
    public function __construct(
        private LifecycleCollection $schedulers,
        private WorkerPool $writeWorkers,
        private WorkerPool $readWorkers,
    ) {
    }

    #[Override]
    public function start(): void
    {
        foreach ($this->schedulers as $scheduler) {
            $scheduler->start();
        }
    }

    #[Override]
    public function drain(): void
    {
        foreach ($this->schedulers as $scheduler) {
            $scheduler->drain();
        }
    }

    // Deliberate: the pools are killed, never shut down gracefully — see ADR-0022
    #[Override]
    public function stop(): void
    {
        foreach ($this->schedulers as $scheduler) {
            $scheduler->stop();
        }

        $this->writeWorkers->kill();
        $this->readWorkers->kill();
    }
}
