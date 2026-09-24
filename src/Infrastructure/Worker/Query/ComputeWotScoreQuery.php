<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Query;

use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class ComputeWotScoreQuery implements ReadQueryInterface
{
    public function __construct(
        private PublicKey $user,
        private PublicKey $target,
    ) {
    }

    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        return $context->getWebOfTrustQuery()->score($this->user, $this->target);
    }
}
