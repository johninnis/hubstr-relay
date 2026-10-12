<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Fake\PlaceholderCountingPdo;
use Innis\Hubstr\Relay\Tests\Support\ActingKeysMother;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventQueryStoreParameterBudgetTest extends TestCase
{
    private const int SQLITE_PARAMETER_LIMIT = 32_766;
    private const int MORE_THAN_THE_LIMIT = 40_000;

    private PlaceholderCountingPdo $pdo;
    private EventQueryStore $queryStore;

    /** @var list<Event> */
    private array $notes;

    private Event $wrapToReader;

    private Event $wrapToAnotherKey;

    protected function setUp(): void
    {
        $this->pdo = new PlaceholderCountingPdo();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->queryStore = new EventQueryStore($this->pdo);

        $writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->notes = array_map(
            static fn (int $at): Event => SignedEventFactory::signedEventAtTime(KeyPair::generate(SignedEventFactory::signer()), EventKind::fromInt(EventKind::TEXT_NOTE), "note at {$at}", $at),
            [100, 200, 300],
        );

        $this->wrapToReader = self::giftWrapAt(250, self::reader());
        $this->wrapToAnotherKey = self::giftWrapAt(150, SignedEventFactory::pubkey('b2'));

        foreach ([...$this->notes, $this->wrapToReader, $this->wrapToAnotherKey] as $event) {
            $writeStore->store($event);
        }
    }

    public function testAReadersKeysBindOneParameterHoweverManyItHolds(): void
    {
        $this->queryStore->findByFilters(new FilterCollection([Filter::from()]), ActingKeysMother::of(...self::fillerKeys(self::MORE_THAN_THE_LIMIT)));

        $this->assertLessThan(10, $this->pdo->mostPlaceholders());
    }

    public function testAFilterWhoseAuthorsExceedTheLimitIsReadInStatementsWithinIt(): void
    {
        $this->queryStore->findByFilters(new FilterCollection([$this->authorsFilter(null)]), ActingKeysMother::anonymous());

        $this->assertLessThanOrEqual(self::SQLITE_PARAMETER_LIMIT, $this->pdo->mostPlaceholders());
    }

    public function testAFilterReadInPartsReturnsTheNewestOfWhatTheWholeFilterMatchesWithinItsLimit(): void
    {
        $read = $this->queryStore->findByFilters(new FilterCollection([$this->authorsFilter(2)]), ActingKeysMother::anonymous());

        $this->assertSame(
            [$this->notes[2]->getId()->toHex(), $this->notes[1]->getId()->toHex()],
            array_map(static fn (StoredEvent $event): string => $event->getHeader()->getId()->toHex(), $read->toArray()),
        );
    }

    public function testACountOfAFilterReadInPartsCountsWhatTheWholeFilterMatches(): void
    {
        $this->assertSame(3, $this->queryStore->countByFilters(new FilterCollection([$this->authorsFilter(null)]), ActingKeysMother::anonymous(), 1000)->toInt());
    }

    public function testAFilterWhoseKindsExceedTheLimitIsReadInStatementsWithinIt(): void
    {
        $this->queryStore->findByFilters(new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts(range(0, 65_535)))]), ActingKeysMother::anonymous());

        $this->assertLessThanOrEqual(self::SQLITE_PARAMETER_LIMIT, $this->pdo->mostPlaceholders());
    }

    public function testAFilterWhoseKindsExceedTheLimitStillReadsWhatItMatches(): void
    {
        $read = $this->queryStore->findByFilters(new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts(range(0, 65_535)))]), ActingKeysMother::anonymous());

        $this->assertCount(3, $read);
    }

    public function testAFilterWhoseAuthorsExceedTheLimitReadsTheGiftWrapsAddressedToTheReaderAndNoOthers(): void
    {
        $read = $this->queryStore->findByFilters(new FilterCollection([$this->authorsFilterNamingEverySigner()]), ActingKeysMother::of(self::reader()));

        $this->assertSame($this->idsOf($this->notes[2], $this->wrapToReader, $this->notes[1], $this->notes[0]), self::idsRead($read));
    }

    public function testAFilterWhoseAuthorsExceedTheLimitReadsNoGiftWrapForAReaderWithNoKeys(): void
    {
        $read = $this->queryStore->findByFilters(new FilterCollection([$this->authorsFilterNamingEverySigner()]), ActingKeysMother::anonymous());

        $this->assertSame($this->idsOf($this->notes[2], $this->notes[1], $this->notes[0]), self::idsRead($read));
    }

    public function testACountOfAFilterWhoseAuthorsExceedTheLimitCountsTheGiftWrapsAddressedToTheReaderAndNoOthers(): void
    {
        $this->assertSame(4, $this->queryStore->countByFilters(new FilterCollection([$this->authorsFilterNamingEverySigner()]), ActingKeysMother::of(self::reader()), 1000)->toInt());
    }

    public function testAFilterWhoseKindsExceedTheLimitReadsTheGiftWrapsAddressedToTheReaderAndNoOthers(): void
    {
        $read = $this->queryStore->findByFilters(new FilterCollection([self::everyKindFilter()]), ActingKeysMother::of(self::reader()));

        $this->assertSame($this->idsOf($this->notes[2], $this->wrapToReader, $this->notes[1], $this->notes[0]), self::idsRead($read));
    }

    public function testACountOfAFilterWhoseKindsExceedTheLimitCountsTheGiftWrapsAddressedToTheReaderAndNoOthers(): void
    {
        $this->assertSame(4, $this->queryStore->countByFilters(new FilterCollection([self::everyKindFilter()]), ActingKeysMother::of(self::reader()), 1000)->toInt());
    }

    private function authorsFilter(?int $limit): Filter
    {
        return Filter::from(
            authors: new PublicKeyCollection([...self::fillerKeys(self::MORE_THAN_THE_LIMIT), ...array_map(static fn (Event $note): PublicKey => $note->getPubkey(), $this->notes)]),
            limit: $limit,
        );
    }

    private function authorsFilterNamingEverySigner(): Filter
    {
        return Filter::from(authors: new PublicKeyCollection([
            ...self::fillerKeys(self::MORE_THAN_THE_LIMIT),
            ...array_map(static fn (Event $event): PublicKey => $event->getPubkey(), [...$this->notes, $this->wrapToReader, $this->wrapToAnotherKey]),
        ]));
    }

    private static function everyKindFilter(): Filter
    {
        return Filter::from(kinds: EventKindCollection::fromInts(range(0, 65_535)));
    }

    private static function giftWrapAt(int $createdAt, PublicKey $recipient): Event
    {
        return SignedEventFactory::signedEventAtTime(
            KeyPair::generate(SignedEventFactory::signer()),
            EventKind::fromInt(EventKind::GIFT_WRAP),
            "gift wrap at {$createdAt}",
            $createdAt,
            new TagCollection([Tag::pubkey($recipient)]),
        );
    }

    private static function reader(): PublicKey
    {
        return SignedEventFactory::pubkey('a1');
    }

    /**
     * @return list<string>
     */
    private function idsOf(Event ...$events): array
    {
        return array_map(static fn (Event $event): string => $event->getId()->toHex(), array_values($events));
    }

    /**
     * @return list<string>
     */
    private static function idsRead(StoredEventCollection $events): array
    {
        return array_map(static fn (StoredEvent $event): string => $event->getHeader()->getId()->toHex(), $events->toArray());
    }

    /**
     * @return list<PublicKey>
     */
    private static function fillerKeys(int $count): array
    {
        return array_map(
            static fn (int $i): PublicKey => PublicKey::tryFromHex(sprintf('%064x', $i)) ?? throw new RuntimeException('filler key did not parse'),
            range(1, $count),
        );
    }
}
