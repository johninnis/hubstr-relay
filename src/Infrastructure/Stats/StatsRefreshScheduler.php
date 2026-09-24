<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Amp\Future;
use Amp\Parallel\Worker\WorkerPool;
use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Relay\Infrastructure\Collection\ScheduledRefreshCollection;
use Innis\Hubstr\Relay\Infrastructure\Process\RepeatingTimer;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class StatsRefreshScheduler implements LifecycleInterface
{
    private const float REFRESH_WINDOW_SECONDS = 600.0;

    private int $cursor = 0;

    /** @var list<ScheduledRefresh> */
    private array $rotation;
    /** @var ?Future<PersistStatResultCommand> */
    private ?Future $inFlight = null;

    // Deliberate: select, submit and apply are one pipeline sharing the in-flight guard — see ADR-0016
    public function __construct(
        private readonly WorkerPool $pool,
        ScheduledRefreshCollection $schedule,
        private readonly WriteCoordinator $writeCoordinator,
        private readonly LoggerInterface $logger,
        private readonly RepeatingTimer $timer = new RepeatingTimer(),
    ) {
        if ($schedule->isEmpty()) {
            throw new InvalidArgumentException('Stats refresh scheduler requires at least one scheduled refresh');
        }

        $this->rotation = $schedule->toArray();
    }

    #[Override]
    public function start(): void
    {
        $this->timer->start($this->tickInterval(), $this->tick(...));
    }

    #[Override]
    public function drain(): void
    {
        $this->timer->stop();
    }

    // Deliberate: an in-flight refresh is abandoned, not awaited — stats recompute from base tables, see ADR-0012
    #[Override]
    public function stop(): void
    {
        $this->pool->kill();
    }

    public function tick(): void
    {
        if (null !== $this->inFlight) {
            return;
        }

        $scheduled = $this->rotation[$this->cursor];
        $this->cursor = ($this->cursor + 1) % count($this->rotation);

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

    private function tickInterval(): float
    {
        return self::REFRESH_WINDOW_SECONDS / count($this->rotation);
    }
}
