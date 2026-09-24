<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Application\Port\ExploreQueryInterface;
use Innis\Hubstr\Relay\Domain\Collection\ExploreEntryCollection;
use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Override;
use PDO;

final readonly class SqliteExploreQuery implements ExploreQueryInterface
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[Override]
    public function findByStat(StatName $stat, ExplorePeriod $period, int $limit): ExploreEntryCollection
    {
        $cutoff = $period->toTimestampCutoff($this->clock->now())?->toInt();
        [$sql, $params] = $this->buildQuery($stat, $cutoff, $stat->cap($limit));

        return new ExploreEntryCollection($this->fetch($sql, $params, $stat));
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function buildQuery(StatName $stat, ?int $cutoff, int $limit): array
    {
        return match ($stat) {
            StatName::TrendingHashtags => $this->trendingHashtagsQuery($cutoff, $limit),
            StatName::MostFollowed => $this->profileTallyQuery(ProfileList::Follows, $cutoff, $limit),
            StatName::MostMuted => $this->profileTallyQuery(ProfileList::Mutes, $cutoff, $limit),
            StatName::MostZapped => $this->zapRecipientsQuery('COUNT(*)', $cutoff, $limit),
            StatName::MostZappedBySats => $this->zapRecipientsQuery('SUM(zr.amount_msats) / 1000', $cutoff, $limit),
            StatName::TopZappers => $this->zapSendersQuery('COUNT(*)', $cutoff, $limit),
            StatName::TopZappersBySats => $this->zapSendersQuery('SUM(zr.amount_msats) / 1000', $cutoff, $limit),
            StatName::MostReactedTo => $this->eventTargetsQuery(EventKind::REACTION, $cutoff, $limit),
            StatName::MostReposted => $this->eventTargetsQuery(EventKind::REPOST, $cutoff, $limit),
            StatName::MostZappedNotes => $this->mostZappedNotesQuery($cutoff, $limit),
        };
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function trendingHashtagsQuery(?int $cutoff, int $limit): array
    {
        if (null === $cutoff) {
            return [
                'SELECT tag_value AS hashtag, COUNT(*) AS count
                 FROM event_tags
                 WHERE tag_name = ?
                 GROUP BY tag_value
                 ORDER BY count DESC
                 LIMIT ?',
                [TagType::HASHTAG, $limit],
            ];
        }

        return [
            'SELECT et.tag_value AS hashtag, COUNT(*) AS count
             FROM events e INDEXED BY idx_events_created
             CROSS JOIN event_tags et INDEXED BY idx_tags_event_name_value
                 ON et.event_id = e.event_id AND et.tag_name = ?
             WHERE e.created_at >= ?
             GROUP BY et.tag_value
             ORDER BY count DESC
             LIMIT ?',
            [TagType::HASHTAG, $cutoff, $limit],
        ];
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function profileTallyQuery(ProfileList $list, ?int $cutoff, int $limit): array
    {
        $table = $list->table();
        $targetColumn = $list->targetColumn();

        if (null === $cutoff) {
            return [
                "SELECT LOWER(HEX({$targetColumn})) AS pubkey, COUNT(*) AS count
                 FROM {$table}
                 GROUP BY {$targetColumn}
                 ORDER BY count DESC
                 LIMIT ?",
                [$limit],
            ];
        }

        return [
            "SELECT LOWER(HEX(t.{$targetColumn})) AS pubkey, COUNT(*) AS count
             FROM {$table} t
             JOIN events e ON e.pubkey = t.{$list->ownerColumn()}
                 AND e.kind = ? AND e.created_at >= ?
             GROUP BY t.{$targetColumn}
             ORDER BY count DESC
             LIMIT ?",
            [$list->sourceKind(), $cutoff, $limit],
        ];
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function zapRecipientsQuery(string $metric, ?int $cutoff, int $limit): array
    {
        if (null === $cutoff) {
            return [
                "SELECT LOWER(HEX(recipient_pubkey)) AS pubkey, {$metric} AS count
                 FROM zap_receipts zr
                 GROUP BY recipient_pubkey
                 ORDER BY count DESC
                 LIMIT ?",
                [$limit],
            ];
        }

        return [
            "SELECT LOWER(HEX(zr.recipient_pubkey)) AS pubkey, {$metric} AS count
             FROM zap_receipts zr
             JOIN events e ON zr.event_id = e.event_id
             WHERE e.created_at >= ?
             GROUP BY zr.recipient_pubkey
             ORDER BY count DESC
             LIMIT ?",
            [$cutoff, $limit],
        ];
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function zapSendersQuery(string $metric, ?int $cutoff, int $limit): array
    {
        if (null === $cutoff) {
            return [
                "SELECT LOWER(HEX(sender_pubkey)) AS pubkey, {$metric} AS count
                 FROM zap_receipts zr
                 WHERE sender_pubkey IS NOT NULL
                 GROUP BY sender_pubkey
                 ORDER BY count DESC
                 LIMIT ?",
                [$limit],
            ];
        }

        return [
            "SELECT LOWER(HEX(zr.sender_pubkey)) AS pubkey, {$metric} AS count
             FROM zap_receipts zr
             JOIN events e ON zr.event_id = e.event_id
             WHERE zr.sender_pubkey IS NOT NULL AND e.created_at >= ?
             GROUP BY zr.sender_pubkey
             ORDER BY count DESC
             LIMIT ?",
            [$cutoff, $limit],
        ];
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function eventTargetsQuery(int $kind, ?int $cutoff, int $limit): array
    {
        if (null === $cutoff) {
            return [
                'SELECT et.tag_value AS pubkey, COUNT(*) AS count
                 FROM events e INDEXED BY idx_events_kind_created
                 JOIN event_tags et INDEXED BY idx_tags_event_name_value
                     ON et.event_id = e.event_id AND et.tag_name = ?
                 WHERE e.kind = ?
                 GROUP BY et.tag_value
                 ORDER BY count DESC
                 LIMIT ?',
                [TagType::PUBKEY, $kind, $limit],
            ];
        }

        return [
            'SELECT et.tag_value AS pubkey, COUNT(*) AS count
             FROM events e INDEXED BY idx_events_kind_created
             JOIN event_tags et INDEXED BY idx_tags_event_name_value
                 ON et.event_id = e.event_id AND et.tag_name = ?
             WHERE e.kind = ? AND e.created_at >= ?
             GROUP BY et.tag_value
             ORDER BY count DESC
             LIMIT ?',
            [TagType::PUBKEY, $kind, $cutoff, $limit],
        ];
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function mostZappedNotesQuery(?int $cutoff, int $limit): array
    {
        if (null === $cutoff) {
            return [
                'SELECT et.tag_value AS event_id, COUNT(*) AS count
                 FROM zap_receipts zr
                 JOIN event_tags et INDEXED BY idx_tags_event_name_value
                     ON et.event_id = zr.event_id AND et.tag_name = ?
                 GROUP BY et.tag_value
                 ORDER BY count DESC
                 LIMIT ?',
                [TagType::EVENT, $limit],
            ];
        }

        return [
            'SELECT et.tag_value AS event_id, COUNT(*) AS count
             FROM zap_receipts zr
             JOIN events e ON zr.event_id = e.event_id
             JOIN event_tags et INDEXED BY idx_tags_event_name_value
                 ON et.event_id = zr.event_id AND et.tag_name = ?
             WHERE e.created_at >= ?
             GROUP BY et.tag_value
             ORDER BY count DESC
             LIMIT ?',
            [TagType::EVENT, $cutoff, $limit],
        ];
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<ExploreEntryInterface>
     */
    private function fetch(string $sql, array $params, StatName $stat): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $entries = [];
        while (false !== ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $entry = $stat->deserialise((array) $row);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
