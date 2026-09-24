<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\Enum;

use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\ValueObject\EventIdCount;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use PHPUnit\Framework\TestCase;

final class StatNameTest extends TestCase
{
    public function testDeserialiseMapsTrendingHashtagsToHashtagCount(): void
    {
        $entry = StatName::TrendingHashtags->deserialise(['hashtag' => 'nostr', 'count' => 5]);

        self::assertInstanceOf(HashtagCount::class, $entry);
        self::assertSame('nostr', (string) $entry->getHashtag());
    }

    public function testDeserialiseMapsMostZappedNotesToEventIdCount(): void
    {
        $entry = StatName::MostZappedNotes->deserialise(['event_id' => str_repeat('a', 64), 'count' => 3]);

        self::assertInstanceOf(EventIdCount::class, $entry);
    }

    public function testDeserialiseMapsRemainingStatsToPubkeyCount(): void
    {
        $entry = StatName::MostFollowed->deserialise(['pubkey' => str_repeat('b', 64), 'count' => 9]);

        self::assertInstanceOf(PubkeyCount::class, $entry);
    }

    public function testDeserialiseRefusesARowWhoseCountIsNotAnInteger(): void
    {
        self::assertNull(StatName::TrendingHashtags->deserialise(['hashtag' => 'nostr', 'count' => '5']));
        self::assertNull(StatName::MostFollowed->deserialise(['pubkey' => str_repeat('b', 64), 'count' => 9.5]));
        self::assertNull(StatName::MostZappedNotes->deserialise(['event_id' => str_repeat('a', 64)]));
    }

    public function testMostZappedNotesCapsLimitToTen(): void
    {
        self::assertSame(10, StatName::MostZappedNotes->cap(50));
        self::assertSame(4, StatName::MostZappedNotes->cap(4));
    }

    public function testOtherStatsLeaveLimitUncapped(): void
    {
        self::assertSame(50, StatName::MostFollowed->cap(50));
    }
}
