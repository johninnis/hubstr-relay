<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Domain\ValueObject\WebOfTrustScore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerWebOfTrustQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\ComputeWotScoreQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Tests\Fake\QueueChannel;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerWebOfTrustQueryTest extends TestCase
{
    public function testScoreQueriesTheReadPoolAndReturnsResult(): void
    {
        $user = self::pubkey('aa');
        $target = self::pubkey('bb');
        $score = new WebOfTrustScore($target, true, 4);
        $channel = new QueueChannel([$score]);
        $query = new WorkerWebOfTrustQuery(new ReadWorkerPool([$channel]));

        $result = $query->score($user, $target);

        self::assertSame($score, $result);
        self::assertInstanceOf(ComputeWotScoreQuery::class, $channel->sent[0]);
    }

    public function testScoreThrowsWhenTheWorkerResultIsNotAWebOfTrustScore(): void
    {
        $query = new WorkerWebOfTrustQuery(new ReadWorkerPool([new QueueChannel(['unexpected'])]));

        $this->expectException(WorkerResultException::class);

        $query->score(self::pubkey('aa'), self::pubkey('bb'));
    }

    private static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid test pubkey');
    }
}
