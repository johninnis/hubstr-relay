<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerExploreQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchExploreStatQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use PHPUnit\Framework\TestCase;

final class WorkerExploreQueryTest extends TestCase
{
    public function testFindByStatQueriesTheReadPoolAndReturnsResult(): void
    {
        $entries = new ExploreEntryCollection([new HashtagCount(Hashtag::fromString('nostr'), 3)]);
        $channel = new QueueChannel([$entries]);
        $query = new WorkerExploreQuery(new ReadWorkerPool([$channel]));

        $result = $query->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10);

        self::assertSame($entries, $result);
        self::assertInstanceOf(FetchExploreStatQuery::class, $channel->sent[0]);
    }

    public function testFindByStatThrowsWhenTheWorkerResultIsNotAnExploreEntryCollection(): void
    {
        $query = new WorkerExploreQuery(new ReadWorkerPool([new QueueChannel(['unexpected'])]));

        $this->expectException(WorkerResultException::class);

        $query->findByStat(StatName::TrendingHashtags, ExplorePeriod::All, 10);
    }
}
