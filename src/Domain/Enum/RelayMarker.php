<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Enum;

enum RelayMarker: string
{
    case Read = 'read';
    case Write = 'write';
    case Both = 'both';

    // Deliberate: an unrecognised marker reads as both, because NIP-65 says an r tag without one covers reading and writing — see ADR-0027
    public static function fromMode(?string $mode): self
    {
        return self::tryFrom($mode ?? '') ?? self::Both;
    }
}
