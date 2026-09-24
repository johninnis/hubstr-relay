<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Stats;

use Closure;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Infrastructure\Stats\RefreshExploreStatTask;
use Innis\Hubstr\Relay\Infrastructure\Stats\RefreshTotalsTask;
use Innis\Hubstr\Relay\Infrastructure\Stats\StatsRefreshSchedule;
use Innis\Hubstr\Relay\Infrastructure\Stats\StatsRefreshScheduler;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\FakeWorkerPool;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class StatsRefreshSchedulerTest extends TestCase
{
    private const string DATABASE_PATH = '/tmp/hubstr-relay-test.sqlite';

    private FakeWorkerPool $pool;
    private StatsRefreshScheduler $scheduler;

    protected function setUp(): void
    {
        $this->pool = new FakeWorkerPool();
        $this->scheduler = new StatsRefreshScheduler(
            $this->pool,
            StatsRefreshSchedule::forDatabase(SqliteDatabase::atPath(self::DATABASE_PATH)),
            new WriteCoordinator(new QueueChannel()),
            new NullLogger(),
        );
    }

    public function testFirstTickSubmitsTotalsTask(): void
    {
        $this->runInLoop(function (): void {
            $this->scheduler->tick();
            $this->pool->completePending();
            $this->settle();

            self::assertCount(1, $this->pool->submitted);
            self::assertInstanceOf(RefreshTotalsTask::class, $this->pool->submitted[0]);
        });
    }

    public function testSubsequentTicksAdvanceThroughExploreStats(): void
    {
        $this->runInLoop(function (): void {
            $totalTicks = 1 + count(StatName::cases());
            for ($i = 0; $i < $totalTicks; ++$i) {
                $this->scheduler->tick();
                $this->pool->completePending();
                $this->settle();
            }

            self::assertCount($totalTicks, $this->pool->submitted);
            self::assertInstanceOf(RefreshTotalsTask::class, $this->pool->submitted[0]);

            for ($i = 1; $i < $totalTicks; ++$i) {
                self::assertInstanceOf(RefreshExploreStatTask::class, $this->pool->submitted[$i]);
            }
        });
    }

    public function testCursorWrapsAfterFullCycle(): void
    {
        $this->runInLoop(function (): void {
            $cycleSize = 1 + count(StatName::cases()) * count(ExplorePeriod::cases());
            for ($i = 0; $i < $cycleSize + 1; ++$i) {
                $this->scheduler->tick();
                $this->pool->completePending();
                $this->settle();
            }

            self::assertCount($cycleSize + 1, $this->pool->submitted);
            self::assertInstanceOf(RefreshTotalsTask::class, $this->pool->submitted[0]);
            self::assertInstanceOf(RefreshTotalsTask::class, $this->pool->submitted[$cycleSize]);
        });
    }

    public function testTickIsSkippedWhilePreviousTaskIsInFlight(): void
    {
        $this->runInLoop(function (): void {
            $this->scheduler->tick();
            $this->scheduler->tick();

            self::assertCount(1, $this->pool->submitted);

            $this->pool->completePending();
            $this->settle();

            $this->scheduler->tick();
            $this->pool->completePending();
            $this->settle();

            self::assertCount(2, $this->pool->submitted);
            self::assertInstanceOf(RefreshTotalsTask::class, $this->pool->submitted[0]);
            self::assertInstanceOf(RefreshExploreStatTask::class, $this->pool->submitted[1]);
        });
    }

    public function testFailingTaskIsLoggedAndDoesNotHaltScheduler(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test', [$testHandler]);
        $scheduler = new StatsRefreshScheduler(
            $this->pool,
            StatsRefreshSchedule::forDatabase(SqliteDatabase::atPath(self::DATABASE_PATH)),
            new WriteCoordinator(new QueueChannel()),
            $logger,
        );

        $this->runInLoop(function () use ($scheduler, $testHandler): void {
            $scheduler->tick();
            $this->pool->failPending(new RuntimeException('boom'));
            $this->settle();

            $scheduler->tick();
            $this->pool->completePending();
            $this->settle();

            self::assertCount(2, $this->pool->submitted);
            self::assertTrue($testHandler->hasErrorThatContains('Stats refresh failed'));
        });
    }

    private function runInLoop(Closure $body): void
    {
        async($body)->await();
    }

    private function settle(): void
    {
        delay(0);
    }
}
