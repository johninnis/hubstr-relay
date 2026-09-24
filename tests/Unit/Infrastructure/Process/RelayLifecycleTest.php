<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Process;

use Innis\Hubstr\Core\Application\Port\LifecycleInterface;
use Innis\Hubstr\Relay\Infrastructure\Collection\LifecycleCollection;
use Innis\Hubstr\Relay\Infrastructure\Process\RelayLifecycle;
use Innis\Hubstr\Relay\Tests\Fake\FakeWorkerPool;
use PHPUnit\Framework\TestCase;

final class RelayLifecycleTest extends TestCase
{
    public function testStartStartsTheSchedulerAndTouchesNoPool(): void
    {
        $scheduler = $this->createMock(LifecycleInterface::class);
        $scheduler->expects(self::once())->method('start');
        $writeWorkers = new FakeWorkerPool();
        $readWorkers = new FakeWorkerPool();

        new RelayLifecycle(new LifecycleCollection([$scheduler]), $writeWorkers, $readWorkers)->start();

        self::assertFalse($writeWorkers->killed || $readWorkers->killed);
    }

    public function testStartStartsEverySchedulerItWasGiven(): void
    {
        $first = $this->createMock(LifecycleInterface::class);
        $first->expects(self::once())->method('start');
        $second = $this->createMock(LifecycleInterface::class);
        $second->expects(self::once())->method('start');

        new RelayLifecycle(new LifecycleCollection([$first, $second]), new FakeWorkerPool(), new FakeWorkerPool())->start();
    }

    public function testDrainDrainsTheSchedulerAndTouchesNoPool(): void
    {
        $scheduler = $this->createMock(LifecycleInterface::class);
        $scheduler->expects(self::once())->method('drain');
        $writeWorkers = new FakeWorkerPool();
        $readWorkers = new FakeWorkerPool();

        new RelayLifecycle(new LifecycleCollection([$scheduler]), $writeWorkers, $readWorkers)->drain();

        self::assertFalse($writeWorkers->killed || $readWorkers->killed);
    }

    public function testStopStopsTheSchedulerThenKillsBothPools(): void
    {
        $writeWorkers = new FakeWorkerPool();
        $readWorkers = new FakeWorkerPool();
        $scheduler = $this->createMock(LifecycleInterface::class);
        $scheduler->expects(self::once())->method('stop')->willReturnCallback(
            static function () use ($writeWorkers, $readWorkers): void {
                self::assertFalse($writeWorkers->killed || $readWorkers->killed, 'the scheduler stops before any pool is killed');
            },
        );

        new RelayLifecycle(new LifecycleCollection([$scheduler]), $writeWorkers, $readWorkers)->stop();

        self::assertTrue($writeWorkers->killed && $readWorkers->killed);
    }
}
