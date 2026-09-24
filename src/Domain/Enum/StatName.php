<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

use Innis\Hubstr\Relay\Domain\ValueObject\EventIdCount;
use Innis\Hubstr\Relay\Domain\ValueObject\ExploreEntryInterface;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;

enum StatName: string
{
    case TrendingHashtags = 'trending_hashtags';
    case MostFollowed = 'most_followed';
    case MostMuted = 'most_muted';
    case MostZapped = 'most_zapped';
    case MostZappedBySats = 'most_zapped_by_sats';
    case TopZappers = 'top_zappers';
    case TopZappersBySats = 'top_zappers_by_sats';
    case MostReactedTo = 'most_reacted_to';
    case MostReposted = 'most_reposted';
    case MostZappedNotes = 'most_zapped_notes';

    private const int MAX_ZAPPED_NOTE_ENTRIES = 10;

    public function cap(int $limit): int
    {
        return match ($this) {
            self::MostZappedNotes => min($limit, self::MAX_ZAPPED_NOTE_ENTRIES),
            default => $limit,
        };
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public function deserialise(array $row): ?ExploreEntryInterface
    {
        return match ($this) {
            self::TrendingHashtags => HashtagCount::tryFromArray($row),
            self::MostZappedNotes => EventIdCount::tryFromArray($row),
            default => PubkeyCount::tryFromArray($row),
        };
    }
}
