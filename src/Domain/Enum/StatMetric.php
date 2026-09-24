<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

enum StatMetric: string
{
    case Events = 'events';
    case Tags = 'tags';
    case Follows = 'follows';
    case Mutes = 'mutes';
    case Relays = 'relays';
    case Zaps = 'zaps';
    case KnownPubkeys = 'known_pubkeys';
}
