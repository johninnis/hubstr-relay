<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Process;

use Innis\Hubstr\Relay\Infrastructure\Process\RepeatingTimer;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class RepeatingTimerTest extends TestCase
{
    public function testStartingTwiceRegistersOneCallback(): void
    {
        $before = count(EventLoop::getIdentifiers());
        $timer = new RepeatingTimer();

        $timer->start(60.0, static fn () => null);
        $timer->start(60.0, static fn () => null);

        $this->assertCount($before + 1, EventLoop::getIdentifiers());
        $timer->stop();
    }

    public function testStoppingCancelsTheCallback(): void
    {
        $before = count(EventLoop::getIdentifiers());
        $timer = new RepeatingTimer();
        $timer->start(60.0, static fn () => null);

        $timer->stop();

        $this->assertCount($before, EventLoop::getIdentifiers());
    }

    public function testStoppingBeforeStartingIsSafe(): void
    {
        new RepeatingTimer()->stop();

        $this->expectNotToPerformAssertions();
    }

    public function testATimerCanBeStartedAgainAfterItWasStopped(): void
    {
        $before = count(EventLoop::getIdentifiers());
        $timer = new RepeatingTimer();
        $timer->start(60.0, static fn () => null);
        $timer->stop();

        $timer->start(60.0, static fn () => null);

        $this->assertCount($before + 1, EventLoop::getIdentifiers());
        $timer->stop();
    }

    public function testTheTickRunsOnTheLoopAtTheInterval(): void
    {
        $ticks = 0;
        $timer = new RepeatingTimer();
        $timer->start(0.0, static function () use ($timer, &$ticks): void {
            ++$ticks;
            $timer->stop();
        });

        EventLoop::run();

        $this->assertSame(1, $ticks);
    }
}
