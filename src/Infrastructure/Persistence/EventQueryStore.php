<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Generator;
use Innis\Hubstr\Relay\Application\Port\RawEventQueryInterface;
use Innis\Hubstr\Relay\Domain\Service\TagValueNormaliser;
use Innis\Hubstr\Relay\Domain\ValueObject\RawEvent;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Override;
use PDO;

final readonly class EventQueryStore implements RawEventQueryInterface
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    // Deliberate: raw JSON, not RawEvent — this crosses a worker channel and the caller re-parses anyway. See ADR-0003.
    /**
     * @return list<string>
     */
    public function findRawJsonByFilters(FilterCollection $filters): array
    {
        $matches = [];

        foreach ($filters as $filter) {
            foreach ($this->queryEvents($filter) as $eventId => $match) {
                $matches[$eventId] = $match;
            }
        }

        uasort($matches, static fn (array $a, array $b): int => [$b['created_at'], $a['id']] <=> [$a['created_at'], $b['id']]);

        return array_map(static fn (array $match): string => $match['raw'], array_values($matches));
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
     * @return array<string, array{created_at: int, id: string, raw: string}>
     */
    private function queryEvents(Filter $filter): array
    {
        [$where, $params, $joins] = $this->buildWhereClause($filter);

        $sql = "SELECT e.event_id, e.created_at, e.raw_event FROM events e {$joins} {$where} ORDER BY e.created_at DESC";

        // Deliberate: a filter that states no limit is not bounded here; the policy gives every filter the ceiling before it reaches the store — see nostr-relay ADR-0017
        if ($filter->hasLimit()) {
            $sql .= ' LIMIT ?';
            $params[] = $filter->getLimit();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $matches = [];
        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $row = (array) $row;
            $eventId = $row['event_id'] ?? null;
            $createdAt = $row['created_at'] ?? null;
            $rawEvent = $row['raw_event'] ?? null;
            if (!is_string($eventId) || !is_string($rawEvent) || !is_numeric($createdAt)) {
                continue;
            }

            $matches[$eventId] = [
                'created_at' => (int) $createdAt,
                'id' => $eventId,
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

        if ($filter->hasIds()) {
            $ids = $filter->getIds();
            if (null === $ids || $ids->isEmpty()) {
                $conditions[] = '1 = 0';
            } else {
                [$condition, $binParams] = self::bytesIn('e.event_id', array_map(static fn (EventId $id): string => $id->toBytes(), $ids->toArray()));
                $conditions[] = $condition;
                array_push($whereParams, ...$binParams);
            }
        }

        if ($filter->hasAuthors()) {
            $authors = $filter->getAuthors();
            if (null === $authors || $authors->isEmpty()) {
                $conditions[] = '1 = 0';
            } else {
                [$condition, $binParams] = self::bytesIn('e.pubkey', array_map(static fn (PublicKey $author): string => $author->toBytes(), $authors->toArray()));
                $conditions[] = $condition;
                array_push($whereParams, ...$binParams);
            }
        }

        if ($filter->hasKinds()) {
            $kinds = $filter->getKinds()?->toInts() ?? [];
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

        if ($filter->hasSearch()) {
            $sanitised = FtsQuery::allTokens($filter->getSearch() ?? '');
            if (null === $sanitised) {
                $conditions[] = '1 = 0';
            } else {
                $joins .= ' INNER JOIN events_fts fts ON fts.rowid = e.rowid';
                $conditions[] = 'fts.content MATCH ?';
                $whereParams[] = $sanitised;
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
