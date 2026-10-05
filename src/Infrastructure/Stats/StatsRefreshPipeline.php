<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Amp\Future;
use Amp\Parallel\Worker\WorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class StatsRefreshPipeline
{
    /** @var ?Future<PersistStatResultCommand> */
    private ?Future $inFlight = null;

    // Deliberate: submit and apply are one unit because the in-flight guard spans both — see ADR-0040
    public function __construct(
        private readonly WorkerPool $pool,
        private readonly WriteCoordinator $writeCoordinator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isBusy(): bool
    {
        return null !== $this->inFlight;
    }

    public function submit(ScheduledRefresh $scheduled): void
    {
        $future = $this->pool->submit($scheduled->getTask())->getFuture();
        $this->inFlight = $future;
        $label = $scheduled->getLabel();

        async(function () use ($future, $label): void {
            try {
                $this->writeCoordinator->apply($future->await());
            } catch (Throwable $error) {
                $this->logger->error('Stats refresh failed', [
                    'task' => $label,
                    'exception' => $error,
                ]);
            } finally {
                $this->inFlight = null;
            }
        });
    }

    public function kill(): void
    {
        $this->pool->kill();
    }
}
