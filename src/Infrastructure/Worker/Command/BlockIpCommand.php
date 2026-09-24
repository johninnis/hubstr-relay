<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Domain\ValueObject\BlockedIp;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class BlockIpCommand implements WriteCommandInterface
{
    public function __construct(
        private BlockedIp $blocked,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->blockIp($this->blocked);

        return null;
    }
}
