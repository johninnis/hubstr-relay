<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Worker;

use Amp\NullCancellation;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchTotalsQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerMessageLoop;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerMessageLoopTest extends TestCase
{
    public function testProcessesEveryAcceptedMessageUntilTheChannelCloses(): void
    {
        $channel = new QueueChannel([new FetchTotalsQuery(), new FetchTotalsQuery()]);

        new WorkerMessageLoop($channel, new NullCancellation())->process(
            ReadQueryInterface::class,
            static fn (ReadQueryInterface $query): string => $query::class,
        );

        self::assertSame([FetchTotalsQuery::class, FetchTotalsQuery::class], $channel->sent);
    }

    public function testStopsWhenAMessageIsNotOfTheAcceptedTypeAndSaysWhy(): void
    {
        $channel = new QueueChannel([new FetchTotalsQuery(), 'STOP', new FetchTotalsQuery()]);

        new WorkerMessageLoop($channel, new NullCancellation())->process(
            ReadQueryInterface::class,
            static fn (ReadQueryInterface $query): string => $query::class,
        );

        self::assertCount(2, $channel->sent);
        self::assertSame(FetchTotalsQuery::class, $channel->sent[0]);

        $failure = $channel->sent[1];
        self::assertInstanceOf(WorkerFailure::class, $failure);
        self::assertStringContainsString('but received string', $failure->getMessage());
    }

    public function testSendsAWorkerFailureWhenTheHandlerThrows(): void
    {
        $channel = new QueueChannel([new FetchTotalsQuery()]);

        new WorkerMessageLoop($channel, new NullCancellation())->process(
            ReadQueryInterface::class,
            static fn (ReadQueryInterface $query): mixed => throw new RuntimeException('kaboom'),
        );

        self::assertCount(1, $channel->sent);

        $failure = $channel->sent[0];
        self::assertInstanceOf(WorkerFailure::class, $failure);
        self::assertStringContainsString('kaboom', $failure->getMessage());
    }
}
