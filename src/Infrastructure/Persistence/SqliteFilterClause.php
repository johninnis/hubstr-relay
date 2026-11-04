<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Service\TagValueNormaliser;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;

// Deliberate: the one compiler of a NIP-01 filter to SQL, shared by the read store and the write store's deletion lookup — see ADR-0046
final class SqliteFilterClause
{
    private function __construct()
    {
    }

    /**
     * @return array{list<string>, list<mixed>, string}
     */
    public static function conditions(Filter $filter): array
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
                $conditions[] = 'e.kind IN ('.self::placeholders(count($kinds)).')';
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
                // Deliberate: a membership test, not a materialised join, so the planner may drive from the events index when the filter is scoped — see ADR-0025
                $conditions[] = 'e.event_id IN (SELECT event_id FROM event_tags WHERE tag_name = ? AND tag_value IN ('.self::placeholders(count($tagValues)).'))';
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

        return [$conditions, $whereParams, $joins];
    }

    /**
     * @param list<string> $conditions
     * @param list<mixed>  $params
     *
     * @return array{string, list<mixed>, string}
     */
    public static function where(array $conditions, array $params, string $joins): array
    {
        return [[] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions), $params, $joins];
    }

    public static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    /**
     * @param list<string> $bytes
     *
     * @return array{string, list<string>}
     */
    public static function bytesIn(string $column, array $bytes): array
    {
        return ["{$column} IN (".self::placeholders(count($bytes)).')', $bytes];
    }
}
