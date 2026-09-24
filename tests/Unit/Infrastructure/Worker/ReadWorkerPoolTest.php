<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Worker;

use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Tests\Fake\ControllableChannel;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class ReadWorkerPoolTest extends TestCase
{
    public function testQueryRoutesThroughAChannelAndReturnsItsResult(): void
    {
        $channel = new QueueChannel([42]);
        $pool = new ReadWorkerPool([$channel]);

        $result = $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));

        self::assertSame(42, $result);
        self::assertInstanceOf(CountByFiltersQuery::class, $channel->sent[0]);
    }

    public function testConcurrentQueriesUseDistinctChannels(): void
    {
        $first = new ControllableChannel();
        $second = new ControllableChannel();
        $pool = new ReadWorkerPool([$first, $second]);

        async(static function () use ($pool, $first, $second): void {
            $a = async(static fn () => $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
            delay(0);
            $b = async(static fn () => $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
            delay(0);

            self::assertCount(1, $first->sent);
            self::assertCount(1, $second->sent);

            $first->answer(1);
            $second->answer(2);

            self::assertSame(1, $a->await());
            self::assertSame(2, $b->await());
        })->await();
    }

    public function testReplacesADeadChannelInsteadOfRecyclingIt(): void
    {
        $replacement = new QueueChannel([42]);
        $dead = new QueueChannel();
        $pool = new ReadWorkerPool([$dead], static fn (): Channel => $replacement);

        try {
            $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
            self::fail('expected the dead channel to surface its error');
        } catch (ChannelException) {
        }

        self::assertSame(42, $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
        self::assertInstanceOf(CountByFiltersQuery::class, $replacement->sent[0]);
    }

    public function testExhaustsLoudlyWhenAllChannelsFailAndCannotBeReplaced(): void
    {
        $dead = new QueueChannel();
        $pool = new ReadWorkerPool([$dead]);

        try {
            $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
            self::fail('expected the dead channel to surface its error');
        } catch (ChannelException) {
        }

        $this->expectException(WorkerResultException::class);
        $this->expectExceptionMessage('Read worker pool exhausted');
        $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
    }

    public function testExhaustsLoudlyWhenChannelReplacementFails(): void
    {
        $dead = new QueueChannel();
        $pool = new ReadWorkerPool([$dead], static function (): Channel {
            throw new RuntimeException('cannot spawn replacement worker');
        });

        try {
            $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
            self::fail('expected the dead channel to surface its error');
        } catch (ChannelException) {
        }

        $this->expectException(WorkerResultException::class);
        $this->expectExceptionMessage('Read worker pool exhausted');
        $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
    }

    public function testQueryThrowsOnWorkerFailureButReusesTheChannel(): void
    {
        $channel = new QueueChannel([new WorkerFailure('query exploded'), 7]);
        $pool = new ReadWorkerPool([$channel]);

        try {
            $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100));
            self::fail('expected the worker failure to surface');
        } catch (WorkerResultException $e) {
            self::assertStringContainsString('query exploded', $e->getMessage());
        }

        self::assertSame(7, $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
        self::assertCount(2, $channel->sent);
    }

    public function testQueryWaitsUntilABusyChannelIsReleased(): void
    {
        $channel = new ControllableChannel();
        $pool = new ReadWorkerPool([$channel]);

        async(static function () use ($pool, $channel): void {
            $first = async(static fn () => $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
            delay(0);
            $second = async(static fn () => $pool->query(new CountByFiltersQuery(new FilterCollection([]), 100)));
            delay(0);

            self::assertCount(1, $channel->sent);

            $channel->answer(1);
            self::assertSame(1, $first->await());
            delay(0);

            self::assertCount(2, $channel->sent);

            $channel->answer(2);
            self::assertSame(2, $second->await());
        })->await();
    }
}
