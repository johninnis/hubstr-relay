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
use Innis\Hubstr\Relay\Tests\Support\ActingKeysMother;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class ReadWorkerPoolTest extends TestCase
{
    public function testQueryReturnsTheResultItsChannelAnswers(): void
    {
        self::assertSame(42, new ReadWorkerPool([new QueueChannel([42])])->query(self::countQuery()));
    }

    public function testQueryRoutesTheQueryThroughAChannel(): void
    {
        $channel = new QueueChannel([42]);
        new ReadWorkerPool([$channel])->query(self::countQuery());

        self::assertInstanceOf(CountByFiltersQuery::class, $channel->sent[0]);
    }

    public function testConcurrentQueriesUseDistinctChannels(): void
    {
        self::assertSame([1, 1], self::twoConcurrentQueries()['in flight per channel']);
    }

    public function testEachConcurrentQueryReturnsItsOwnChannelsAnswer(): void
    {
        self::assertSame([1, 2], self::twoConcurrentQueries()['answers']);
    }

    public function testADeadChannelSurfacesItsError(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel()], static fn (): Channel => new QueueChannel([42]));

        $this->expectException(ChannelException::class);

        $pool->query(self::countQuery());
    }

    public function testAQueryAfterADeadChannelIsAnsweredByItsReplacement(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel()], static fn (): Channel => new QueueChannel([42]));
        self::surfaceTheDeadChannel($pool);

        self::assertSame(42, $pool->query(self::countQuery()));
    }

    public function testAQueryAfterADeadChannelIsSentToItsReplacementRatherThanTheDeadOne(): void
    {
        $replacement = new QueueChannel([42]);
        $pool = new ReadWorkerPool([new QueueChannel()], static fn (): Channel => $replacement);
        self::surfaceTheDeadChannel($pool);
        $pool->query(self::countQuery());

        self::assertInstanceOf(CountByFiltersQuery::class, $replacement->sent[0]);
    }

    public function testExhaustsLoudlyWhenAllChannelsFailAndCannotBeReplaced(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel()]);
        self::surfaceTheDeadChannel($pool);

        self::assertStringContainsString('Read worker pool exhausted', self::workerResultFailureOf($pool));
    }

    public function testExhaustsLoudlyWhenChannelReplacementFails(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel()], static function (): Channel {
            throw new RuntimeException('cannot spawn replacement worker');
        });
        self::surfaceTheDeadChannel($pool);

        self::assertStringContainsString('Read worker pool exhausted', self::workerResultFailureOf($pool));
    }

    public function testAWorkerFailureSurfacesWithItsMessage(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel([new WorkerFailure('query exploded'), 7])]);

        self::assertStringContainsString('query exploded', self::workerResultFailureOf($pool));
    }

    public function testAQueryAfterAWorkerFailureIsAnswered(): void
    {
        $pool = new ReadWorkerPool([new QueueChannel([new WorkerFailure('query exploded'), 7])]);
        self::workerResultFailureOf($pool);

        self::assertSame(7, $pool->query(self::countQuery()));
    }

    public function testAQueryAfterAWorkerFailureReusesTheSameChannel(): void
    {
        $channel = new QueueChannel([new WorkerFailure('query exploded'), 7]);
        $pool = new ReadWorkerPool([$channel]);
        self::workerResultFailureOf($pool);
        $pool->query(self::countQuery());

        self::assertCount(2, $channel->sent);
    }

    public function testASecondQueryWaitsWhileTheOnlyChannelIsBusy(): void
    {
        self::assertSame(1, self::twoQueriesOnOneChannel()['sent while busy']);
    }

    public function testTheFirstQueryIsAnsweredWhenTheChannelAnswersIt(): void
    {
        self::assertSame(1, self::twoQueriesOnOneChannel()['first answer']);
    }

    public function testTheWaitingQueryIsSentOnceTheChannelIsReleased(): void
    {
        self::assertSame(2, self::twoQueriesOnOneChannel()['sent once released']);
    }

    public function testTheWaitingQueryIsAnsweredByTheReleasedChannel(): void
    {
        self::assertSame(2, self::twoQueriesOnOneChannel()['second answer']);
    }

    private static function countQuery(): CountByFiltersQuery
    {
        return new CountByFiltersQuery(new FilterCollection([]), ActingKeysMother::anonymous(), 100);
    }

    private static function surfaceTheDeadChannel(ReadWorkerPool $pool): void
    {
        try {
            $pool->query(self::countQuery());
        } catch (ChannelException) {
            return;
        }

        throw new RuntimeException('expected the dead channel to surface its error');
    }

    private static function workerResultFailureOf(ReadWorkerPool $pool): string
    {
        try {
            $pool->query(self::countQuery());
        } catch (WorkerResultException $failure) {
            return $failure->getMessage();
        }

        throw new RuntimeException('expected the worker failure to surface');
    }

    /**
     * @return array{'in flight per channel': list<int>, answers: list<mixed>}
     */
    private static function twoConcurrentQueries(): array
    {
        $first = new ControllableChannel();
        $second = new ControllableChannel();
        $pool = new ReadWorkerPool([$first, $second]);

        $a = async(static fn () => $pool->query(self::countQuery()));
        delay(0);
        $b = async(static fn () => $pool->query(self::countQuery()));
        delay(0);
        $inFlight = [count($first->sent), count($second->sent)];

        $first->answer(1);
        $second->answer(2);

        return ['in flight per channel' => $inFlight, 'answers' => [$a->await(), $b->await()]];
    }

    /**
     * @return array{'sent while busy': int, 'first answer': mixed, 'sent once released': int, 'second answer': mixed}
     */
    private static function twoQueriesOnOneChannel(): array
    {
        $channel = new ControllableChannel();
        $pool = new ReadWorkerPool([$channel]);

        $first = async(static fn () => $pool->query(self::countQuery()));
        delay(0);
        $second = async(static fn () => $pool->query(self::countQuery()));
        delay(0);
        $sentWhileBusy = count($channel->sent);

        $channel->answer(1);
        $firstAnswer = $first->await();
        delay(0);
        $sentOnceReleased = count($channel->sent);

        $channel->answer(2);

        return ['sent while busy' => $sentWhileBusy, 'first answer' => $firstAnswer, 'sent once released' => $sentOnceReleased, 'second answer' => $second->await()];
    }
}
