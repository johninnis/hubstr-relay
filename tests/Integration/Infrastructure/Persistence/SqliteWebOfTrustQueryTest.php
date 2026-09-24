<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SqliteWebOfTrustQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteWebOfTrustQueryTest extends TestCase
{
    private PDO $pdo;
    private EventWriteStore $eventStore;
    private SqliteWebOfTrustQuery $webOfTrustQuery;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->eventStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->webOfTrustQuery = new SqliteWebOfTrustQuery($this->pdo);
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testDirectFollowReturnsDistanceOne(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $this->storeFollowList($this->keyPair, [$target->getPublicKey()->toHex()]);

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $target->getPublicKey());

        $this->assertTrue($score->isFollowed());
        $this->assertSame(1, $score->getDistance());
        $this->assertSame(0, $score->getMutualFollows());
    }

    public function testFriendOfFriendReturnsDistanceTwo(): void
    {
        $friend = KeyPair::generate(SignedEventFactory::signer());
        $target = KeyPair::generate(SignedEventFactory::signer());

        $this->storeFollowList($this->keyPair, [$friend->getPublicKey()->toHex()]);
        $this->storeFollowList($friend, [$target->getPublicKey()->toHex()]);

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $target->getPublicKey());

        $this->assertFalse($score->isFollowed());
        $this->assertSame(2, $score->getDistance());
        $this->assertSame(1, $score->getMutualFollows());
    }

    public function testMutualFollowsCountsEveryIntermediary(): void
    {
        $friendA = KeyPair::generate(SignedEventFactory::signer());
        $friendB = KeyPair::generate(SignedEventFactory::signer());
        $friendC = KeyPair::generate(SignedEventFactory::signer());
        $target = KeyPair::generate(SignedEventFactory::signer());

        $this->storeFollowList($this->keyPair, [
            $friendA->getPublicKey()->toHex(),
            $friendB->getPublicKey()->toHex(),
            $friendC->getPublicKey()->toHex(),
        ]);
        $this->storeFollowList($friendA, [$target->getPublicKey()->toHex()]);
        $this->storeFollowList($friendB, [$target->getPublicKey()->toHex()]);

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $target->getPublicKey());

        $this->assertSame(2, $score->getMutualFollows());
        $this->assertSame(2, $score->getDistance());
    }

    public function testUnreachableTargetReturnsNullDistance(): void
    {
        $stranger = KeyPair::generate(SignedEventFactory::signer());

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $stranger->getPublicKey());

        $this->assertFalse($score->isFollowed());
        $this->assertSame(0, $score->getMutualFollows());
        $this->assertNull($score->getDistance());
    }

    public function testDirectFollowTakesPrecedenceOverMutualCount(): void
    {
        $friend = KeyPair::generate(SignedEventFactory::signer());
        $target = KeyPair::generate(SignedEventFactory::signer());

        $this->storeFollowList($this->keyPair, [
            $friend->getPublicKey()->toHex(),
            $target->getPublicKey()->toHex(),
        ]);
        $this->storeFollowList($friend, [$target->getPublicKey()->toHex()]);

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $target->getPublicKey());

        $this->assertTrue($score->isFollowed());
        $this->assertSame(1, $score->getMutualFollows());
        $this->assertSame(1, $score->getDistance());
    }

    public function testToArrayFormat(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $this->storeFollowList($this->keyPair, [$target->getPublicKey()->toHex()]);

        $score = $this->webOfTrustQuery->score($this->keyPair->getPublicKey(), $target->getPublicKey());

        $this->assertSame([
            'pubkey' => $target->getPublicKey()->toHex(),
            'followed' => true,
            'mutual_follows' => 0,
            'distance' => 1,
        ], $score->toArray());
    }

    /**
     * @param list<string> $followedHexKeys
     */
    private function storeFollowList(KeyPair $follower, array $followedHexKeys): void
    {
        $tags = array_map(
            static fn (string $hex) => Tag::tryFromArray(['p', $hex]),
            $followedHexKeys,
        );

        $event = SignedEventFactory::signedEvent($follower, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection($tags));
        $this->eventStore->store($event);
    }
}
