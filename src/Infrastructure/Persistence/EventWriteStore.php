<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\Exception\MalformedEventRowException;
use Innis\Hubstr\Relay\Domain\Service\BlacklistFilter;
use Innis\Hubstr\Relay\Domain\Service\TagValueNormaliser;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Infrastructure\Worker\WorkerFailure;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EventKindCategory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventVersion;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Override;
use PDO;
use Throwable;

final readonly class EventWriteStore implements EventWriterInterface
{
    public function __construct(
        private PDO $pdo,
        private StatementRunner $statements,
        private Denormaliser $denormaliser,
    ) {
    }

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        return $this->storeOneTransactionally($event);
    }

    /**
     * @return list<EventStoreOutcome|WorkerFailure>
     */
    // Deliberate: one transaction per event, never one enclosing the batch — see ADR-0005
    public function storeBatch(EventCollection $events): array
    {
        $outcomes = [];

        foreach ($events as $event) {
            try {
                $outcomes[] = $this->storeOneTransactionally($event);
            } catch (Throwable $e) {
                $outcomes[] = WorkerFailure::fromThrowable($e);
            }
        }

        return $outcomes;
    }

    private function storeOneTransactionally(Event $event): EventStoreOutcome
    {
        $this->pdo->beginTransaction();

        try {
            $outcome = $this->storeOne($event);
            $this->pdo->commit();

            return $outcome;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        if ($eventIds->isEmpty()) {
            return 0;
        }

        $params = array_map(static fn (EventId $id) => $id->toBytes(), $eventIds->toArray());
        $params[] = $author->toBytes();

        // Deliberate: prepared on the connection for this one call, never through the runner — a statement whose placeholder count varies would be cached once per distinct count for the life of the connection — see ADR-0020
        $placeholders = implode(',', array_fill(0, $eventIds->count(), '?'));
        $statement = $this->pdo->prepare("DELETE FROM events WHERE event_id IN ({$placeholders}) AND pubkey = ?");
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        $authorBin = $author->toBytes();
        $deleted = 0;

        $sql = 'DELETE FROM events WHERE event_id IN (
                SELECT e.event_id FROM events e
                INNER JOIN event_tags t ON t.event_id = e.event_id
                WHERE e.pubkey = ? AND e.kind = ? AND t.tag_name = ? AND t.tag_value = ?
            )';

        foreach ($coordinates as $coordinate) {
            $deleted += $this->statements->execute($sql, [
                $authorBin,
                $coordinate->getKind()->toInt(),
                TagType::IDENTIFIER,
                $coordinate->getIdentifier(),
            ]);
        }

        return $deleted;
    }

    public function deleteByContentMatchChunk(BlacklistWord $word, int $limit): int
    {
        $rowIds = $this->rowIdsRefusedBy(new BlacklistFilter(new BlacklistWordCollection([$word])), $limit);

        if ([] === $rowIds) {
            return 0;
        }

        // Deliberate: prepared on the connection for this one call, never through the runner — the row count differs on every final chunk — see ADR-0020
        $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
        $statement = $this->pdo->prepare("DELETE FROM events WHERE rowid IN ({$placeholders})");
        $statement->execute($rowIds);

        return $statement->rowCount();
    }

    /**
     * @return list<int>
     */
    private function rowIdsRefusedBy(BlacklistFilter $filter, int $limit): array
    {
        $rowIds = [];

        $statement = $this->pdo->prepare('SELECT rowid, content FROM events');
        $statement->execute();

        while (count($rowIds) < $limit && false !== ($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $row = (array) $row;
            $rowId = $row['rowid'] ?? null;
            $content = $row['content'] ?? null;

            if (is_numeric($rowId) && is_string($content) && $filter->containsBlacklistedWord($content)) {
                $rowIds[] = (int) $rowId;
            }
        }

        $statement->closeCursor();

        return $rowIds;
    }

    public function deleteByHashtagChunk(Hashtag $hashtag, int $limit): int
    {
        return $this->statements->execute(
            'DELETE FROM events WHERE event_id IN (
                SELECT event_id FROM event_tags WHERE tag_name = ? AND tag_value = ? LIMIT ?
            )',
            [TagType::HASHTAG, (string) $hashtag, $limit],
        );
    }

    // Deliberate: any stated expiry that is a non-empty run of digits with no leading zero and has passed, which is exactly what Event::isExpiredAt calls expired — see ADR-0028
    public function deleteExpiredChunk(Timestamp $now, int $limit): int
    {
        return $this->statements->execute(
            'DELETE FROM events WHERE event_id IN (
                SELECT DISTINCT event_id FROM event_tags
                WHERE tag_name = ?
                  AND tag_value GLOB ?
                  AND tag_value NOT GLOB ?
                  AND tag_value NOT GLOB ?
                  AND CAST(tag_value AS INTEGER) BETWEEN 0 AND ?
                LIMIT ?
            )',
            [TagType::EXPIRATION, '[0-9]*', '*[^0-9]*', '0?*', $now->toInt(), $limit],
        );
    }

    public function deleteByPubkeyChunk(PublicKey $pubkey, int $limit): int
    {
        return $this->statements->execute(
            'DELETE FROM events WHERE event_id IN (
                SELECT event_id FROM events WHERE pubkey = ? LIMIT ?
            )',
            [$pubkey->toBytes(), $limit],
        );
    }

    private function storeOne(Event $event): EventStoreOutcome
    {
        $eventIdBin = $event->getId()->toBytes();

        if ($this->eventExists($eventIdBin)) {
            return EventStoreOutcome::Duplicate;
        }

        $kind = $event->getKind();

        if (EventKindCategory::Replaceable === $kind->category() && !$this->handleReplaceableEvent($event)) {
            return EventStoreOutcome::Superseded;
        }

        if (EventKindCategory::Addressable === $kind->category() && !$this->handleParameterisedReplaceableEvent($event)) {
            return EventStoreOutcome::Superseded;
        }

        $this->insertEvent($event, $eventIdBin);
        $this->insertTags($event, $eventIdBin);
        $this->denormaliser->denormalise($event);

        return EventStoreOutcome::Stored;
    }

    private function eventExists(string $eventIdBin): bool
    {
        return null !== $this->statements->selectRow('SELECT 1 FROM events WHERE event_id = ?', [$eventIdBin]);
    }

    private function handleReplaceableEvent(Event $event): bool
    {
        return $this->supersedeIfNewer($event, $this->statements->selectRow(
            'SELECT event_id, created_at FROM events WHERE pubkey = ? AND kind = ?',
            [$event->getPubkey()->toBytes(), $event->getKind()->toInt()],
        ));
    }

    private function handleParameterisedReplaceableEvent(Event $event): bool
    {
        $dTag = $event->getTags()->getFirstValueByType(TagType::identifier()) ?? '';

        return $this->supersedeIfNewer($event, $this->statements->selectRow(
            'SELECT e.event_id, e.created_at FROM events e
             INNER JOIN event_tags t ON t.event_id = e.event_id
             WHERE e.pubkey = ? AND e.kind = ? AND t.tag_name = ? AND t.tag_value = ?',
            [$event->getPubkey()->toBytes(), $event->getKind()->toInt(), TagType::IDENTIFIER, $dTag],
        ));
    }

    /**
     * @param array<array-key, mixed>|null $existing
     */
    private function supersedeIfNewer(Event $event, ?array $existing): bool
    {
        if (null === $existing) {
            return true;
        }

        $existingVersion = self::storedVersion($existing, $event);

        if (!EventVersion::of($event)->supersedes($existingVersion)) {
            return false;
        }

        $this->statements->execute('DELETE FROM events WHERE event_id = ?', [$existingVersion->getId()->toBytes()]);

        return true;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function storedVersion(array $row, Event $incoming): EventVersion
    {
        $createdAt = $row['created_at'] ?? null;
        $eventId = $row['event_id'] ?? null;

        if (!is_numeric($createdAt) || !is_string($eventId)) {
            throw new MalformedEventRowException(self::rowFault('is missing its created_at or event_id', $incoming));
        }

        return new EventVersion(
            Timestamp::fromInt((int) $createdAt),
            EventId::tryFromBytes($eventId) ?? throw new MalformedEventRowException(self::rowFault('carries an event_id that is not 32 bytes', $incoming)),
        );
    }

    private static function rowFault(string $problem, Event $incoming): string
    {
        return sprintf(
            'The stored event this kind %d from %s would replace %s',
            $incoming->getKind()->toInt(),
            $incoming->getPubkey()->toHex(),
            $problem,
        );
    }

    private function insertEvent(Event $event, string $eventIdBin): void
    {
        $this->statements->execute(
            'INSERT INTO events (event_id, pubkey, kind, created_at, content, raw_event) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $eventIdBin,
                $event->getPubkey()->toBytes(),
                $event->getKind()->toInt(),
                $event->getCreatedAt()->toInt(),
                (string) $event->getContent(),
                $event->toJson(),
            ],
        );
    }

    private function insertTags(Event $event, string $eventIdBin): void
    {
        $kind = $event->getKind();
        // Deliberate: the p tags of a follow or mute list are not indexed, so a #p filter does not find them — see ADR-0029
        $skipPubkeyTags = $kind->is(EventKind::FOLLOW_LIST) || $kind->is(EventKind::MUTE_LIST);

        foreach ($event->getTags() as $tag) {
            $value = $tag->getValue();
            $type = $tag->getType();

            if (null === $value || ($skipPubkeyTags && $type->is(TagType::PUBKEY))) {
                continue;
            }

            $this->statements->execute(
                'INSERT OR IGNORE INTO event_tags (event_id, tag_name, tag_value) VALUES (?, ?, ?)',
                [$eventIdBin, (string) $type, TagValueNormaliser::normalise($type, $value)],
            );
        }
    }
}
