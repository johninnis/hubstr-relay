<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Service\BlacklistFilter;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventQueryStore;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventStorageTest extends TestCase
{
    private const int COUNT_LIMIT = 1000;

    private PDO $pdo;
    private EventWriteStore $writeStore;
    private EventQueryStore $queryStore;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->writeStore = WriteContext::forConnection($this->pdo)->getEventWriteStore();
        $this->queryStore = new EventQueryStore($this->pdo);
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    /**
     * @param list<Filter|null> $filters
     *
     * @return list<Event>
     */
    private function findEvents(array $filters, int $limit = 100): array
    {
        return array_map(
            static function (string $rawEvent): Event {
                $event = Event::tryFromJson($rawEvent);
                self::assertNotNull($event);

                return $event;
            },
            $this->queryStore->findRawJsonByFilters(new FilterCollection($filters)),
        );
    }

    public function testStoreAndRetrieveEvent(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'Hello Nostr!');

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($event));

        $results = $this->findEvents(
            [Filter::tryFromArray(['ids' => [$event->getId()->toHex()]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame($event->getId()->toHex(), $results[0]->getId()->toHex());
        $this->assertSame('Hello Nostr!', (string) $results[0]->getContent());
    }

    public function testFindByFiltersResultsCarryVerbatimRawJson(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'Hello Nostr!');
        $this->writeStore->store($event);

        $results = $this->findEvents(
            [Filter::tryFromArray(['ids' => [$event->getId()->toHex()]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame(
            json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $results[0]->getRawJson(),
        );
    }

    public function testInvalidHexFilterValuesAreRejectedAtTheBoundary(): void
    {
        $this->assertNull(Filter::tryFromArray(['authors' => ['npub1notvalidhex']]));
        $this->assertNull(Filter::tryFromArray(['ids' => ['zz', 'not-hex']]));
    }

    public function testDuplicateTagsAreStoredOnce(): void
    {
        $tags = new TagCollection([Tag::hashtag(Hashtag::fromString('nostr')), Tag::hashtag(Hashtag::fromString('nostr'))]);
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'tagged', $tags);
        $this->writeStore->store($event);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_tags WHERE tag_name = 't' AND tag_value = 'nostr'");
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDuplicateReturnsDuplicateOutcome(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'Hello');

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($event));
        $this->assertSame(EventStoreOutcome::Duplicate, $this->writeStore->store($event));
    }

    public function testStoreBatchReturnsOutcomePerEvent(): void
    {
        $first = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'first');
        $second = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'second');

        $outcomes = $this->writeStore->storeBatch(new EventCollection([$first, $second]));

        $this->assertSame([EventStoreOutcome::Stored, EventStoreOutcome::Stored], $outcomes);
    }

    public function testStoreBatchReportsDuplicateForRepeatedEventWithinBatch(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'once');

        $outcomes = $this->writeStore->storeBatch(new EventCollection([$event, $event]));

        $this->assertSame([EventStoreOutcome::Stored, EventStoreOutcome::Duplicate], $outcomes);
    }

    public function testStoreBatchSupersedesOlderReplaceableWithinBatch(): void
    {
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"new"}', time());
        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"old"}', time() - 100);

        $outcomes = $this->writeStore->storeBatch(new EventCollection([$newer, $older]));

        $this->assertSame([EventStoreOutcome::Stored, EventStoreOutcome::Superseded], $outcomes);
    }

    public function testAFailingEventRollsBackOnlyItselfAndItsNeighboursStillCommit(): void
    {
        $before = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'before');
        $failing = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '');
        $after = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'after');

        $this->pdo->exec('DROP TABLE profile_follows');

        $outcomes = $this->writeStore->storeBatch(new EventCollection([$before, $failing, $after]));

        $this->assertSame(EventStoreOutcome::Stored, $outcomes[0]);
        $this->assertInstanceOf(WorkerFailure::class, $outcomes[1]);
        $this->assertSame(EventStoreOutcome::Stored, $outcomes[2]);
    }

    public function testAFailingEventLeavesNoPartialRowsOfItsOwn(): void
    {
        $failing = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '');

        $this->pdo->exec('DROP TABLE profile_follows');
        $this->writeStore->storeBatch(new EventCollection([$failing]));

        $this->assertSame(0, $this->countStoredEvents());
    }

    public function testNeighboursOfAFailingEventAreReadableAfterTheBatch(): void
    {
        $before = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'before');
        $failing = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '');
        $after = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'after');

        $this->pdo->exec('DROP TABLE profile_follows');
        $this->writeStore->storeBatch(new EventCollection([$before, $failing, $after]));

        $this->assertSame(
            2,
            $this->countStoredEvents(),
            'A batch is a transport optimisation, not an atomicity boundary: one failing event must not '
            .'roll back the unrelated submissions batched alongside it (ADR-0005).',
        );
    }

    private function countStoredEvents(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM events');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testReplaceableEventNewerReplacesOlder(): void
    {
        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"old"}', time() - 100);
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"new"}', time());

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($older));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($newer));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [0]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame('{"name":"new"}', (string) $results[0]->getContent());
    }

    public function testReplaceableEventOlderIsRejected(): void
    {
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"new"}', time());
        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"old"}', time() - 100);

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($newer));
        $this->assertSame(EventStoreOutcome::Superseded, $this->writeStore->store($older));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [0]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame('{"name":"new"}', (string) $results[0]->getContent());
    }

    public function testReplaceableTimestampTieRetainsLowestId(): void
    {
        [$lower, $higher] = $this->replaceablePairAtSameTimestamp();

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($higher));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($lower));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [0]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame($lower->getId()->toHex(), $results[0]->getId()->toHex());
    }

    public function testReplaceableTimestampTieRejectsHigherId(): void
    {
        [$lower, $higher] = $this->replaceablePairAtSameTimestamp();

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($lower));
        $this->assertSame(EventStoreOutcome::Superseded, $this->writeStore->store($higher));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [0]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame($lower->getId()->toHex(), $results[0]->getId()->toHex());
    }

    /**
     * @return array{Event, Event}
     */
    private function replaceablePairAtSameTimestamp(): array
    {
        $timestamp = time();
        $first = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"a"}', $timestamp);
        $second = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::METADATA), '{"name":"b"}', $timestamp);

        return strcmp($first->getId()->toHex(), $second->getId()->toHex()) < 0
            ? [$first, $second]
            : [$second, $first];
    }

    public function testReplaceableKind3ContactList(): void
    {
        $first = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', time() - 100);
        $second = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', time());

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($first));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($second));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [3]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame($second->getId()->toHex(), $results[0]->getId()->toHex());
    }

    public function testReplaceableKind10000Range(): void
    {
        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::MUTE_LIST), '', time() - 100);
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::MUTE_LIST), '', time());

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($older));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($newer));

        $this->assertSame(1, $this->queryStore->countByFilters(
            new FilterCollection([Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [10000]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testParameterisedReplaceableEvent(): void
    {
        $tags = new TagCollection([Tag::identifier('my-article')]);

        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::LONGFORM_CONTENT), 'v1', time() - 100, $tags);
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::LONGFORM_CONTENT), 'v2', time(), $tags);

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($older));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($newer));

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [30023]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame('v2', (string) $results[0]->getContent());
    }

    public function testParameterisedReplaceableDifferentDTags(): void
    {
        $article1 = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::LONGFORM_CONTENT), 'Article 1', new TagCollection([Tag::identifier('article-1')]));
        $article2 = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::LONGFORM_CONTENT), 'Article 2', new TagCollection([Tag::identifier('article-2')]));

        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($article1));
        $this->assertSame(EventStoreOutcome::Stored, $this->writeStore->store($article2));

        $this->assertCount(2, $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()], 'kinds' => [30023]])],
        ));
    }

    public function testFilterByAuthor(): void
    {
        $otherKeyPair = KeyPair::generate(SignedEventFactory::signer());

        $myEvent = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'mine');
        $otherEvent = SignedEventFactory::signedEvent($otherKeyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'theirs');

        $this->writeStore->store($myEvent);
        $this->writeStore->store($otherEvent);

        $results = $this->findEvents(
            [Filter::tryFromArray(['authors' => [$this->keyPair->getPublicKey()->toHex()]])],
        );

        $this->assertCount(1, $results);
        $this->assertSame('mine', (string) $results[0]->getContent());
    }

    public function testFilterByKind(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'note'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+'));

        $results = $this->findEvents([Filter::tryFromArray(['kinds' => [7]])]);

        $this->assertCount(1, $results);
        $this->assertSame('+', (string) $results[0]->getContent());
    }

    public function testFilterByTag(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'tagged post', new TagCollection([Tag::hashtag(Hashtag::fromString('nostr'))])));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'no tag'));

        $results = $this->findEvents([Filter::tryFromArray(['#t' => ['nostr']])]);

        $this->assertCount(1, $results);
        $this->assertSame('tagged post', (string) $results[0]->getContent());
    }

    public function testFilterByTagIsCaseInsensitiveForHashtags(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'mixed', new TagCollection([Tag::hashtag(Hashtag::fromString('Bitcoin'))])));

        $results = $this->findEvents([Filter::tryFromArray(['#t' => ['BITCOIN']])]);

        $this->assertCount(1, $results);
        $this->assertSame('mixed', (string) $results[0]->getContent());
    }

    public function testFollowListPubkeyTagsAreNotStoredInEventTags(): void
    {
        $followed = SignedEventFactory::pubkey('aa');
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::tryFromArray(['p', $followed->toHex()]),
            Tag::tryFromArray(['t', 'somehashtag']),
        ])));

        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM event_tags WHERE tag_name = 'p'"), 'p tags on kind 3 should not be stored in event_tags');
        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM event_tags WHERE tag_name = 't'"), 'other tags on kind 3 should still be stored');
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM profile_follows'), 'profile_follows must still capture the relationship');
    }

    public function testMuteListPubkeyTagsAreNotStoredInEventTags(): void
    {
        $muted = SignedEventFactory::pubkey('bb');
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::MUTE_LIST), '', new TagCollection([
            Tag::tryFromArray(['p', $muted->toHex()]),
        ])));

        $this->assertSame(0, $this->countRows("SELECT COUNT(*) FROM event_tags WHERE tag_name = 'p'"));
        $this->assertSame(1, $this->countRows('SELECT COUNT(*) FROM profile_mutes'));
    }

    public function testTextNotePubkeyTagsAreStillStored(): void
    {
        $mentioned = SignedEventFactory::pubkey('cc');
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'mention', new TagCollection([
            Tag::tryFromArray(['p', $mentioned->toHex()]),
        ])));

        $this->assertSame(1, $this->countRows("SELECT COUNT(*) FROM event_tags WHERE tag_name = 'p'"));
    }

    private function countRows(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function testFilterBySinceAndUntil(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'old', 1000));
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'mid', 2000));
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'new', 3000));

        $results = $this->findEvents([Filter::tryFromArray(['since' => 1500, 'until' => 2500])]);

        $this->assertCount(1, $results);
        $this->assertSame('mid', (string) $results[0]->getContent());
    }

    public function testFilterLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), "note {$i}", time() + $i));
        }

        $this->assertCount(3, $this->findEvents([Filter::tryFromArray(['limit' => 3])]));
    }

    public function testFilterResultsOrderedByCreatedAtDesc(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'first', 1000));
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'second', 2000));
        $this->writeStore->store(SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'third', 3000));

        $results = $this->findEvents([Filter::tryFromArray([])], 100);

        $this->assertSame('third', (string) $results[0]->getContent());
        $this->assertSame('second', (string) $results[1]->getContent());
        $this->assertSame('first', (string) $results[2]->getContent());
    }

    public function testFts5Search(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'Bitcoin is freedom money'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'Nice weather today'));

        $results = $this->findEvents([Filter::tryFromArray(['search' => 'bitcoin'])]);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Bitcoin', (string) $results[0]->getContent());
    }

    public function testFts5SearchAndsMultipleTerms(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'bitcoin and freedom'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'bitcoin and money'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'gold and freedom'));

        $results = $this->findEvents([Filter::tryFromArray(['search' => 'bitcoin freedom'])]);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('bitcoin and freedom', (string) $results[0]->getContent());
    }

    public function testFts5SearchTreatsOperatorsAsLiteralTokens(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'bitcoin freedom'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'gold money'));

        $results = $this->findEvents([Filter::tryFromArray(['search' => 'bitcoin OR gold'])]);

        $this->assertCount(0, $results);
    }

    public function testFts5SearchSurvivesEmbeddedQuotes(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'plain text'));

        $results = $this->findEvents([Filter::tryFromArray(['search' => 'foo"bar'])]);

        $this->assertCount(0, $results);
    }

    public function testFts5SearchWithOnlyWhitespaceReturnsNothing(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'some content'));

        $results = $this->findEvents([Filter::tryFromArray(['search' => '   '])]);

        $this->assertCount(0, $results);
    }

    public function testCountByFilters(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), "note {$i}"));
        }
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+'));

        $this->assertSame(5, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByContentMatchSingleWord(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'this is spam content'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'clean note'));

        $this->assertSame(1, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam'), 100));
        $this->assertSame(1, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByContentMatchRemovesTheWordsAsWritten(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'a spam scam offer'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'only spam here'));

        $this->assertSame(1, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam scam'), 100));
        $this->assertSame(1, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByContentMatchSparesEventsThatMerelyUseBothWordsApart(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam crypto scam pitch'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'scam crypto spam reversed'));

        $this->assertSame(0, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam scam'), 100));
    }

    #[DataProvider('bannedPhraseContents')]
    public function testAPurgeRemovesAnEventOnlyIfTheContentFilterWouldRefuseIt(string $content): void
    {
        $banned = BlacklistWord::fromString('ban word');
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), $content);
        $this->writeStore->store($event);

        $refused = new BlacklistFilter(new BlacklistWordCollection([$banned]))->isBlacklisted($event);
        $removed = $this->writeStore->deleteByContentMatchChunk($banned, 100) > 0;

        $this->assertSame($refused, $removed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bannedPhraseContents(): iterable
    {
        yield 'says the phrase' => ['this is a ban word here'];
        yield 'both words apart' => ['a ban somewhere and a word elsewhere'];
        yield 'reversed' => ['word then ban'];
        yield 'run together' => ['banword all one'];
        yield 'different case' => ['a BAN WORD here'];
        yield 'separated by punctuation' => ['a ban, word here'];
        yield 'only one of them' => ['just a ban here'];
        yield 'inside a longer word' => ['this is a ban wordy thing'];
    }

    public function testABannedWordIsRemovedFromInsideALongerWord(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'that is spammy'));

        $this->assertSame(1, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam'), 100));
    }

    public function testAWordTooShortToBanCannotReachTheDelete(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'keep me'));

        $this->assertNull(BlacklistWord::tryFromString('   '));
        $this->assertNull(BlacklistWord::tryFromString(' '));
        $this->assertNull(BlacklistWord::tryFromString('e'));
        $this->assertSame(1, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByEventIds(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'to delete');
        $this->writeStore->store($event);

        $this->assertSame(1, $this->writeStore->deleteByEventIds(new EventIdCollection([$event->getId()]), $this->keyPair->getPublicKey()));
        $this->assertCount(0, $this->findEvents([Filter::tryFromArray(['ids' => [$event->getId()->toHex()]])]));
    }

    public function testDeleteByEventIdsWrongAuthorDoesNothing(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'keep this');
        $this->writeStore->store($event);

        $this->assertSame(0, $this->writeStore->deleteByEventIds(new EventIdCollection([$event->getId()]), KeyPair::generate(SignedEventFactory::signer())->getPublicKey()));
        $this->assertCount(1, $this->findEvents([Filter::tryFromArray(['ids' => [$event->getId()->toHex()]])]));
    }

    public function testDeleteByCoordinates(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::LONGFORM_CONTENT), 'article', new TagCollection([Tag::identifier('my-article')]));
        $this->writeStore->store($event);

        $coordinate = EventCoordinate::tryFromParts(30023, $this->keyPair->getPublicKey()->toHex(), 'my-article');

        $this->assertSame(1, $this->writeStore->deleteByCoordinates(new EventCoordinateCollection([$coordinate]), $this->keyPair->getPublicKey()));
        $this->assertSame(0, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [30023]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByContentMatchChunkHonoursLimit(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam three'));

        $this->assertSame(2, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam'), 2));
        $this->assertSame(1, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam'), 2));
        $this->assertSame(0, $this->writeStore->deleteByContentMatchChunk(BlacklistWord::fromString('spam'), 2));
    }

    public function testDeleteByPubkeyChunkRemovesAuthorEvents(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam');
        $this->writeStore->store($event);

        $this->assertSame(1, $this->writeStore->deleteByPubkeyChunk($event->getPubkey(), 100));
        $this->assertSame(0, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testDeleteByPubkeyChunkHonoursLimit(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'three'));

        $pubkey = $this->keyPair->getPublicKey();

        $this->assertSame(2, $this->writeStore->deleteByPubkeyChunk($pubkey, 2));
        $this->assertSame(1, $this->writeStore->deleteByPubkeyChunk($pubkey, 2));
        $this->assertSame(0, $this->writeStore->deleteByPubkeyChunk($pubkey, 2));
    }

    #[DataProvider('expirationValues')]
    public function testAChunkRemovesExactlyWhatTheLibraryCallsExpired(string $expiration): void
    {
        $reference = Timestamp::fromInt(1_000_000);
        $event = SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            'expires at '.$expiration,
            new TagCollection([Tag::tryFromArray(['expiration', $expiration])]),
        );
        $this->writeStore->store($event);

        $deleted = $this->writeStore->deleteExpiredChunk($reference, 100);

        $this->assertSame($event->isExpiredAt($reference) ? 1 : 0, $deleted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expirationValues(): iterable
    {
        yield 'long past' => ['100'];
        yield 'the reference instant' => ['1000000'];
        yield 'one second away' => ['1000001'];
        yield 'the epoch' => ['0'];
        yield 'a padded zero' => ['00'];
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'signed' => ['+100'];
        yield 'leading space' => [' 100'];
        yield 'trailing space' => ['100 '];
        yield 'words' => ['soon'];
        yield 'digits then letters' => ['12abc'];
        yield 'leading zero' => ['0100'];
        yield 'heavily padded' => ['00000000000000000100'];
        yield 'the largest integer' => ['9223372036854775807'];
        yield 'beyond the largest integer' => ['99999999999999999999'];
        yield 'exponent notation' => ['1e6'];
        yield 'decimal notation' => ['100.0'];
        yield 'arabic-indic digits' => ['١٠'];
        yield 'underscore separated' => ['1_000'];
    }

    /**
     * @param list<string> $expirations
     */
    #[DataProvider('multipleExpirations')]
    public function testAChunkRemovesExactlyWhatTheLibraryCallsExpiredWhateverTheTagOrder(string $label, array $expirations): void
    {
        $reference = Timestamp::fromInt(1_000_000);
        $event = SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $label,
            new TagCollection(array_map(static fn (string $value): ?Tag => Tag::tryFromArray(['expiration', $value]), $expirations)),
        );
        $this->writeStore->store($event);

        $deleted = $this->writeStore->deleteExpiredChunk($reference, 100);

        $this->assertSame($event->isExpiredAt($reference) ? 1 : 0, $deleted);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function multipleExpirations(): iterable
    {
        yield 'future then past' => ['future then past', ['9999999999', '100']];
        yield 'past then future' => ['past then future', ['100', '9999999999']];
        yield 'both past' => ['both past', ['100', '200']];
        yield 'both future' => ['both future', ['8000000000', '9000000000']];
        yield 'past then unreadable' => ['past then unreadable', ['100', 'soon']];
        yield 'unreadable then past' => ['unreadable then past', ['soon', '100']];
        yield 'unreadable only' => ['unreadable only', ['soon', 'later']];
        yield 'past then padded' => ['past then padded', ['100', '0200']];
        yield 'past and the epoch' => ['past and the epoch', ['100', '0']];
    }

    public function testTheSameExpiriesInEitherOrderGiveTheSameAnswer(): void
    {
        $reference = Timestamp::fromInt(1_000_000);
        $this->storeWithExpirations('past first', ['100', '9999999999']);
        $this->storeWithExpirations('future first', ['9999999999', '100']);

        $this->assertSame(2, $this->writeStore->deleteExpiredChunk($reference, 100));
    }

    /**
     * @param list<string> $expirations
     */
    private function storeWithExpirations(string $content, array $expirations): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $content,
            new TagCollection(array_map(static fn (string $value): ?Tag => Tag::tryFromArray(['expiration', $value]), $expirations)),
        ));
    }

    public function testDeleteExpiredChunkLeavesAnEventWithNoExpirationTag(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'plain'));

        $this->assertSame(0, $this->writeStore->deleteExpiredChunk(Timestamp::fromInt(PHP_INT_MAX), 100));
    }

    public function testDeleteExpiredChunkStopsAtItsLimit(): void
    {
        $this->storeWithExpiration('one', '100');
        $this->storeWithExpiration('two', '100');
        $this->storeWithExpiration('three', '100');

        $this->assertSame(2, $this->writeStore->deleteExpiredChunk(Timestamp::fromInt(200), 2));
        $this->assertCount(1, $this->remainingContents());
    }

    public function testDeleteExpiredChunkTakesTheEventsTagsWithIt(): void
    {
        $this->storeWithExpiration('gone', '100');

        $this->writeStore->deleteExpiredChunk(Timestamp::fromInt(200), 100);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM event_tags');

        $this->assertSame(0, (int) ($stmt instanceof PDOStatement ? $stmt->fetchColumn() : 1));
    }

    private function storeWithExpiration(string $content, string $expiration): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent(
            $this->keyPair,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            $content,
            new TagCollection([Tag::tryFromArray(['expiration', $expiration])]),
        ));
    }

    /**
     * @return list<string>
     */
    private function remainingContents(): array
    {
        $stmt = $this->pdo->query('SELECT content FROM events ORDER BY content');

        return $stmt instanceof PDOStatement ? array_values(array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN))) : [];
    }

    public function testDeleteByHashtagChunkRemovesTaggedEvents(): void
    {
        $tagged = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'tagged', new TagCollection([Tag::hashtag(Hashtag::fromString('spam'))]));
        $this->writeStore->store($tagged);
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'untagged'));

        $this->assertSame(1, $this->writeStore->deleteByHashtagChunk(Hashtag::fromString('spam'), 100));
        $this->assertSame(1, $this->queryStore->countByFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), self::COUNT_LIMIT)->toInt());
    }

    public function testMultipleFiltersUnionResults(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'a note'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::REACTION), '+'));

        $this->assertCount(2, $this->findEvents([
            Filter::tryFromArray(['kinds' => [1]]),
            Filter::tryFromArray(['kinds' => [7]]),
        ]));
    }

    public function testMultipleFiltersDeduplicateResults(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');
        $this->writeStore->store($event);

        $this->assertCount(1, $this->findEvents([
            Filter::tryFromArray(['kinds' => [1]]),
            Filter::tryFromArray(['ids' => [$event->getId()->toHex()]]),
        ]));
    }

    public function testTagsWithCascadeDelete(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'tagged', new TagCollection([Tag::hashtag(Hashtag::fromString('test'))]));
        $this->writeStore->store($event);
        $this->writeStore->deleteByEventIds(new EventIdCollection([$event->getId()]), $this->keyPair->getPublicKey());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_tags');
        $stmt->execute();
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testEmptyFilterReturnsAllEvents(): void
    {
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'one'));
        $this->writeStore->store(SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'two'));

        $this->assertCount(2, $this->findEvents([Filter::tryFromArray([])], 100));
    }

    public function testDeletionEventPersistsAfterProcessing(): void
    {
        $target = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'to be deleted');
        $this->writeStore->store($target);

        $deletionTags = new TagCollection([
            Tag::event($target->getId()),
            Tag::tryFromArray(['k', '1']),
        ]);
        $deletion = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::EVENT_DELETION), 'removing', $deletionTags);
        $this->writeStore->store($deletion);

        $this->writeStore->deleteByEventIds(new EventIdCollection([$target->getId()]), $this->keyPair->getPublicKey());

        $results = $this->findEvents([Filter::tryFromArray(['kinds' => [5]])]);
        $this->assertCount(1, $results);
        $this->assertSame($deletion->getId()->toHex(), $results[0]->getId()->toHex());
    }

    public function testStoredEventPreservesSignature(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'signed content');
        $this->writeStore->store($event);

        $results = $this->findEvents([Filter::tryFromArray(['ids' => [$event->getId()->toHex()]])]);

        $this->assertTrue($results[0]->verify(SignedEventFactory::signer()));
        $this->assertSame($event->getSignature()->toHex(), $results[0]->getSignature()->toHex());
    }
}
