<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Generator;
use Innis\Hubstr\Relay\Application\Port\RawEventQueryInterface;
use Innis\Hubstr\Relay\Domain\Exception\MalformedEventRowException;
use Innis\Hubstr\Relay\Domain\Service\TagValueNormaliser;
use Innis\Hubstr\Relay\Domain\ValueObject\RawEvent;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Service\ExpirationDerivation;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Override;
use PDO;

final readonly class EventQueryStore implements RawEventQueryInterface
{
    // Deliberate: SQLite binds at most 32766 parameters per statement by default and one filter is one statement binding each of its values, so the relay's per-filter value ceiling stays below that with room for the tag names, the scalar bounds and the tenants and kinds guest scoping adds — see ADR-0038
    public const int MAX_FILTER_VALUES = 30_000;

    private const int EXPIRY_LOOKUP_CHUNK = 500;

    public function __construct(
        private PDO $pdo,
    ) {
    }

    // Deliberate: stored events, read from indexed columns and the stored bytes, never parsed — see ADR-0035
    public function findByFilters(FilterCollection $filters): StoredEventCollection
    {
        $matches = [];

        foreach ($filters as $filter) {
            foreach ($this->queryEvents($filter) as $eventId => $match) {
                $matches[$eventId] = $match;
            }
        }

        uasort($matches, static fn (array $a, array $b): int => [$b['created_at'], $a['id']] <=> [$a['created_at'], $b['id']]);

        $statedExpiries = $this->statedExpiries(array_column($matches, 'id'));

        return new StoredEventCollection(array_map(
            static fn (array $match): StoredEvent => self::storedEvent($match, $statedExpiries[$match['id']] ?? []),
            array_values($matches),
        ));
    }

    /**
     * @param array{created_at: int, id: string, pubkey: string, kind: int, raw: string} $match
     * @param list<string>                                                               $statedExpiries
     */
    private static function storedEvent(array $match, array $statedExpiries): StoredEvent
    {
        $header = new EventHeader(
            EventId::tryFromBytes($match['id']) ?? throw new MalformedEventRowException('A stored event carries an event_id that is not 32 bytes'),
            PublicKey::tryFromBytes($match['pubkey']) ?? throw new MalformedEventRowException(sprintf('Stored event %s carries a pubkey that is not 32 bytes', bin2hex($match['id']))),
            EventKind::tryFromInt($match['kind']) ?? throw new MalformedEventRowException(sprintf('Stored event %s carries kind %d, which is out of range', bin2hex($match['id']), $match['kind'])),
        );

        // Deliberate: fromOwnStore is never parsed — rows written since v0.2.0 hold EncodedEvent::of() output, and rows from before hold the bytes of an event verified at admission — see ADR-0034
        return new StoredEvent($header, ExpirationDerivation::earliestStated($statedExpiries), EncodedEvent::fromOwnStore($match['raw']));
    }

    /**
     * @param list<string> $eventIds
     *
     * @return array<string, list<string>>
     */
    private function statedExpiries(array $eventIds): array
    {
        $expiries = [];

        foreach (array_chunk($eventIds, self::EXPIRY_LOOKUP_CHUNK) as $chunk) {
            [$condition, $params] = self::bytesIn('event_id', $chunk);
            $stmt = $this->pdo->prepare("SELECT event_id, tag_value FROM event_tags WHERE tag_name = 'expiration' AND {$condition}");
            $stmt->execute($params);

            while (false !== ($row = $stmt->fetch(PDO::FETCH_NUM))) {
                [$eventId, $value] = (array) $row + [null, null];

                if (is_string($eventId) && is_string($value)) {
                    $expiries[$eventId][] = $value;
                }
            }
        }

        return $expiries;
    }

    // Deliberate: a count stops at the ceiling and says so, and counts the union the filter set matches rather than summing the filters — see ADR-0024
    public function countByFilters(FilterCollection $filters, int $limit): EventCount
    {
        $matched = [];
        $capped = false;

        foreach ($filters as $filter) {
            $eventIds = $this->queryEventIds($filter, $limit);
            $capped = $capped || count($eventIds) >= $limit;

            foreach ($eventIds as $eventId) {
                $matched[$eventId] = true;
            }
        }

        $total = count($matched);

        return $capped || $total > $limit
            ? EventCount::approximate(min($total, $limit))
            : EventCount::exact($total);
    }

    #[Override]
    public function findRawByFilter(Filter $filter): Generator
    {
        [$where, $params, $joins] = $this->buildWhereClause($filter);

        $sql = "SELECT e.event_id, e.raw_event FROM events e {$joins} {$where} ORDER BY e.created_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row = (array) $row;
            $eventId = $row['event_id'] ?? null;
            $rawEvent = $row['raw_event'] ?? null;
            if (!is_string($eventId) || !is_string($rawEvent)) {
                continue;
            }

            $id = EventId::tryFromBytes($eventId);
            if (null === $id) {
                continue;
            }

            yield new RawEvent($id, $rawEvent);
        }
    }

    /**
     * @return array<string, array{created_at: int, id: string, pubkey: string, kind: int, raw: string}>
     */
    private function queryEvents(Filter $filter): array
    {
        [$where, $params, $joins] = $this->buildWhereClause($filter);

        $sql = "SELECT e.event_id, e.pubkey, e.kind, e.created_at, e.raw_event FROM events e {$joins} {$where} ORDER BY e.created_at DESC";

        // Deliberate: a filter that states no limit is not bounded here; the policy gives every filter the ceiling before it reaches the store — see nostr-relay ADR-0024
        $limit = $filter->getLimit();
        if (null !== $limit) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $matches = [];
        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row = (array) $row;
            $eventId = $row['event_id'] ?? null;
            $pubkey = $row['pubkey'] ?? null;
            $kind = $row['kind'] ?? null;
            $createdAt = $row['created_at'] ?? null;
            $rawEvent = $row['raw_event'] ?? null;
            if (!is_string($eventId) || !is_string($pubkey) || !is_numeric($kind) || !is_string($rawEvent) || !is_numeric($createdAt)) {
                continue;
            }

            $matches[$eventId] = [
                'created_at' => (int) $createdAt,
                'id' => $eventId,
                'pubkey' => $pubkey,
                'kind' => (int) $kind,
                'raw' => $rawEvent,
            ];
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function queryEventIds(Filter $filter, int $limit): array
    {
        [$where, $params, $joins] = $this->buildWhereClause($filter);
        $params[] = $limit;

        $sql = "SELECT e.event_id FROM events e {$joins} {$where} LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), is_string(...)));
    }

    /**
     * @return array{string, list<mixed>, string}
     */
    private function buildWhereClause(Filter $filter): array
    {
        $conditions = [];
        $whereParams = [];
        $joins = '';

        $ids = $filter->getIds();
        if (null !== $ids) {
            if ($ids->isEmpty()) {
                $conditions[] = '1 = 0';
            } else {
                [$condition, $binParams] = self::bytesIn('e.event_id', array_map(static fn (EventId $id): string => $id->toBytes(), $ids->toArray()));
                $conditions[] = $condition;
                array_push($whereParams, ...$binParams);
            }
        }

        $authors = $filter->getAuthors();
        if (null !== $authors) {
            if ($authors->isEmpty()) {
                $conditions[] = '1 = 0';
            } else {
                [$condition, $binParams] = self::bytesIn('e.pubkey', array_map(static fn (PublicKey $author): string => $author->toBytes(), $authors->toArray()));
                $conditions[] = $condition;
                array_push($whereParams, ...$binParams);
            }
        }

        $kinds = $filter->getKinds()?->toInts();
        if (null !== $kinds) {
            if ([] === $kinds) {
                $conditions[] = '1 = 0';
            } else {
                $placeholders = implode(',', array_fill(0, count($kinds), '?'));
                $conditions[] = "e.kind IN ({$placeholders})";
                array_push($whereParams, ...$kinds);
            }
        }

        $tags = $filter->getTags();
        if (null !== $tags) {
            foreach ($tags->getValues() as $tagName => $tagValues) {
                if ([] === $tagValues) {
                    $conditions[] = '1 = 0';
                    continue;
                }

                $type = TagType::fromString($tagName);
                $valuePlaceholders = implode(',', array_fill(0, count($tagValues), '?'));
                // Deliberate: a membership test, not a materialised join, so the planner may drive from the events index when the filter is scoped — see ADR-0025
                $conditions[] = "e.event_id IN (SELECT event_id FROM event_tags WHERE tag_name = ? AND tag_value IN ({$valuePlaceholders}))";
                $whereParams[] = $tagName;
                foreach ($tagValues as $tagValue) {
                    $whereParams[] = TagValueNormaliser::normalise($type, $tagValue);
                }
            }
        }

        if (null !== $filter->getSince()) {
            $conditions[] = 'e.created_at >= ?';
            $whereParams[] = $filter->getSince()->toInt();
        }

        if (null !== $filter->getUntil()) {
            $conditions[] = 'e.created_at <= ?';
            $whereParams[] = $filter->getUntil()->toInt();
        }

        $search = $filter->getSearch();
        if (null !== $search) {
            $sanitised = FtsQuery::allTokens($search);
            if (null !== $sanitised) {
                $joins .= ' INNER JOIN events_fts fts ON fts.rowid = e.rowid';
                $conditions[] = 'fts.content MATCH ?';
                $whereParams[] = $sanitised;
            } elseif ('' === trim($search)) {
                $conditions[] = '1 = 0';
            }
        }

        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);

        return [$where, $whereParams, $joins];
    }

    /**
     * @param list<string> $bytes
     *
     * @return array{string, list<string>}
     */
    private static function bytesIn(string $column, array $bytes): array
    {
        $placeholders = implode(',', array_fill(0, count($bytes), '?'));

        return ["{$column} IN ({$placeholders})", $bytes];
    }
}
