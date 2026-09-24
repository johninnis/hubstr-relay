<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\Port;

use Innis\Hubstr\Relay\Domain\ValueObject\WebOfTrustScore;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface WebOfTrustQueryInterface
{
    public function score(PublicKey $user, PublicKey $target): WebOfTrustScore;
}
