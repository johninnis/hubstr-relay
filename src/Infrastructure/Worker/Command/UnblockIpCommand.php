<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Override;

final readonly class UnblockIpCommand implements WriteCommandInterface
{
    public function __construct(
        private IpAddress $ip,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->unblockIp($this->ip);

        return null;
    }
}
