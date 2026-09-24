<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\StatTotals;
use Innis\Hubstr\Relay\Domain\ValueObject\WebOfTrustScore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\ComputeWotScoreQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\CountByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchExploreStatQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FetchTotalsQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\Query\FindByFiltersQuery;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadContextTest extends TestCase
{
    private PDO $pdo;
    private ReadContext $context;
    private EventWriteStore $writeStore;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->context = ReadContext::forConnection($this->pdo);
        $this->writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testFindByFiltersQueryReturnsMatchingEvents(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'find me');
        $this->writeStore->store($event);

        $result = new FindByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]))->applyTo($this->context);

        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertIsString($result[0]);
        self::assertSame($event->getId()->toHex(), (Event::tryFromJson($result[0]) ?? throw new RuntimeException('Invalid event'))->getId()->toHex());
    }

    public function testFindByFiltersQueryHonoursTheLimitOnTheFilter(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));

        $result = new FindByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1], 'limit' => 1])]))->applyTo($this->context);

        self::assertIsArray($result);
        self::assertCount(1, $result);
    }

    public function testCountByFiltersQueryReturnsCount(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));

        $result = new CountByFiltersQuery(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), 100)->applyTo($this->context);

        self::assertEquals(EventCount::exact(2), $result);
    }

    public function testFetchTotalsQueryReturnsStatTotals(): void
    {
        $result = new FetchTotalsQuery()->applyTo($this->context);

        self::assertInstanceOf(StatTotals::class, $result);
    }

    public function testFetchExploreStatQueryReturnsEntryCollection(): void
    {
        $result = new FetchExploreStatQuery(StatName::TrendingHashtags, ExplorePeriod::All, 10)->applyTo($this->context);

        self::assertInstanceOf(ExploreEntryCollection::class, $result);
    }

    public function testComputeWotScoreQueryReturnsScoreForADirectFollow(): void
    {
        $target = KeyPair::generate(SignedEventFactory::signer());
        $followList = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::pubkey($target->getPublicKey()),
        ]));
        $this->writeStore->store($followList);

        $result = new ComputeWotScoreQuery($this->keyPair->getPublicKey(), $target->getPublicKey())
            ->applyTo($this->context);

        self::assertInstanceOf(WebOfTrustScore::class, $result);
        self::assertTrue($result->isFollowed());
    }
}
