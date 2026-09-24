<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class AddTenantCommand implements WriteCommandInterface
{
    public function __construct(
        private PublicKey $pubkey,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->addTenant($this->pubkey);

        return null;
    }
}
