<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

enum BlacklistType: string
{
    case Word = 'word';
    case Pubkey = 'pubkey';
    case Hashtag = 'hashtag';
}
