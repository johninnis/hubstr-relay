<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Retention;

use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Relay\Application\Port\EventPurgerInterface;
use Innis\Hubstr\Relay\Infrastructure\Process\RepeatingTimer;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ExpirySweepScheduler implements LifecycleInterface
{
    // Deliberate: the same window the stats rotation takes, so nothing the relay derives trails an expiry by more than one of them — see ADR-0028
    private const float SWEEP_INTERVAL_SECONDS = 600.0;

    public function __construct(
        private EventPurgerInterface $purger,
        private LoggerInterface $logger,
        private RepeatingTimer $timer = new RepeatingTimer(),
    ) {
    }

    #[Override]
    public function start(): void
    {
        $this->timer->start(self::SWEEP_INTERVAL_SECONDS, $this->tick(...));
    }

    #[Override]
    public function drain(): void
    {
        $this->timer->stop();
    }

    // Deliberate: the sweep owns no pool of its own, so there is nothing to kill here — see ADR-0022
    #[Override]
    public function stop(): void
    {
    }

    public function tick(): void
    {
        try {
            $this->purger->purgeExpired();
        } catch (Throwable $error) {
            $this->logger->error('Expired event sweep failed', ['exception' => $error]);
        }
    }
}
