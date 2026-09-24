<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;

interface EventPurgerInterface
{
    public function purgeByPubkey(PublicKey $pubkey): void;

    public function purgeByContentMatch(BlacklistWord $word): void;

    public function purgeByHashtag(Hashtag $hashtag): void;

    public function purgeExpired(): void;
}
