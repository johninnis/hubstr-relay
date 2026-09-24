<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

enum ExplorePeriod: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';
    case All = 'all';

    public function toTimestampCutoff(Timestamp $now): ?Timestamp
    {
        return match ($this) {
            self::Day => Timestamp::fromInt($now->toInt() - 86400),
            self::Week => Timestamp::fromInt($now->toInt() - 604800),
            self::Month => Timestamp::fromInt($now->toInt() - 2592000),
            self::All => null,
        };
    }
}
