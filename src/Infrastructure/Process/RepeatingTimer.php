<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Process;

use Closure;
use Revolt\EventLoop;

final class RepeatingTimer
{
    private ?string $callbackId = null;

    public function start(float $intervalSeconds, Closure $tick): void
    {
        $this->callbackId ??= EventLoop::repeat($intervalSeconds, $tick);
    }

    public function stop(): void
    {
        if (null === $this->callbackId) {
            return;
        }

        EventLoop::cancel($this->callbackId);
        $this->callbackId = null;
    }
}
