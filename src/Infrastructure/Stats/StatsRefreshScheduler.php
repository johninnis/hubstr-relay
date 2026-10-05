<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Relay\Infrastructure\Process\RepeatingTimer;
use Override;

final class StatsRefreshScheduler implements LifecycleInterface
{
    public function __construct(
        private readonly StatsRefreshRotation $rotation,
        private readonly StatsRefreshPipeline $pipeline,
        private readonly RepeatingTimer $timer = new RepeatingTimer(),
    ) {
    }

    #[Override]
    public function start(): void
    {
        $this->timer->start($this->rotation->tickInterval(), $this->tick(...));
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
        $this->pipeline->kill();
    }

    public function tick(): void
    {
        if ($this->pipeline->isBusy()) {
            return;
        }

        $this->pipeline->submit($this->rotation->next());
    }
}
