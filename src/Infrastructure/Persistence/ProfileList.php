<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

enum ProfileList
{
    case Follows;
    case Mutes;

    public function table(): string
    {
        return match ($this) {
            self::Follows => 'profile_follows',
            self::Mutes => 'profile_mutes',
        };
    }

    public function ownerColumn(): string
    {
        return match ($this) {
            self::Follows => 'follower_pubkey',
            self::Mutes => 'muter_pubkey',
        };
    }

    public function targetColumn(): string
    {
        return match ($this) {
            self::Follows => 'followed_pubkey',
            self::Mutes => 'muted_pubkey',
        };
    }

    public function sourceKind(): int
    {
        return match ($this) {
            self::Follows => EventKind::FOLLOW_LIST,
            self::Mutes => EventKind::MUTE_LIST,
        };
    }
}
