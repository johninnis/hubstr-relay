<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Domain\Enum\StatName;
use Innis\Hubstr\Relay\Domain\Exception\MalformedStatPayloadException;
use Innis\Hubstr\Relay\Domain\ValueObject\HashtagCount;
use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use Innis\Hubstr\Relay\Infrastructure\Persistence\StatPayloadCodec;
use Innis\Hubstr\Relay\Tests\Support\StatTotalsMother;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatPayloadCodecTest extends TestCase
{
    private StatPayloadCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new StatPayloadCodec();
    }

    public function testEncodeAndDecodeRoundTripForHashtagEntries(): void
    {
        $entries = [
            new HashtagCount(Hashtag::fromString('nostr'), 5),
            new HashtagCount(Hashtag::fromString('bitcoin'), 3),
        ];

        $decoded = $this->codec->decodeEntries(
            StatName::TrendingHashtags,
            $this->codec->encodeEntries($entries),
        );

        self::assertCount(2, $decoded);
        self::assertSame($entries[0]->toArray(), $decoded[0]->toArray());
        self::assertSame($entries[1]->toArray(), $decoded[1]->toArray());
    }

    public function testEncodeAndDecodeRoundTripForPubkeyEntries(): void
    {
        $pubkey = PublicKey::tryFromHex(str_repeat('a', 64))
            ?? throw new RuntimeException('Invalid test pubkey');
        $entries = [new PubkeyCount($pubkey, 12)];

        $decoded = $this->codec->decodeEntries(
            StatName::MostFollowed,
            $this->codec->encodeEntries($entries),
        );

        self::assertCount(1, $decoded);
        self::assertSame($entries[0]->toArray(), $decoded[0]->toArray());
    }

    public function testDecodeEntriesRejectsAPayloadThatIsNotAJsonArray(): void
    {
        $this->expectException(MalformedStatPayloadException::class);

        $this->codec->decodeEntries(StatName::TrendingHashtags, '42');
    }

    public function testDecodeEntriesRejectsMalformedJson(): void
    {
        $this->expectException(MalformedStatPayloadException::class);

        $this->codec->decodeEntries(StatName::TrendingHashtags, '{not json');
    }

    public function testDecodeEntriesSkipsRowsThatAreNotArrays(): void
    {
        $decoded = $this->codec->decodeEntries(
            StatName::TrendingHashtags,
            '["not a row", {"hashtag": "nostr", "count": 1}]',
        );

        self::assertCount(1, $decoded);
        self::assertInstanceOf(HashtagCount::class, $decoded[0]);
        self::assertSame('nostr', (string) $decoded[0]->getHashtag());
    }

    public function testEncodeAndDecodeRoundTripForTotals(): void
    {
        $totals = StatTotalsMother::populated();

        $restored = $this->codec->decodeTotals($this->codec->encodeTotals($totals));

        self::assertSame($totals->toArray(), $restored->toArray());
    }

    public function testDecodeTotalsRejectsAPayloadThatIsNotAJsonObject(): void
    {
        $this->expectException(MalformedStatPayloadException::class);

        $this->codec->decodeTotals('42');
    }
}
