<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerStatsProvider;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchTotalsQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Hubstr\Relay\Tests\Support\StatTotalsMother;
use PHPUnit\Framework\TestCase;

final class WorkerStatsProviderTest extends TestCase
{
    public function testGetStatsQueriesTheReadPoolAndReturnsResult(): void
    {
        $totals = StatTotalsMother::withEvents(5);
        $channel = new QueueChannel([$totals]);
        $provider = new WorkerStatsProvider(new ReadWorkerPool([$channel]));

        $result = $provider->getStats();

        self::assertSame($totals, $result);
        self::assertInstanceOf(FetchTotalsQuery::class, $channel->sent[0]);
    }

    public function testGetStatsThrowsWhenTheWorkerResultIsNotStatTotals(): void
    {
        $provider = new WorkerStatsProvider(new ReadWorkerPool([new QueueChannel(['unexpected'])]));

        $this->expectException(WorkerResultException::class);

        $provider->getStats();
    }
}
