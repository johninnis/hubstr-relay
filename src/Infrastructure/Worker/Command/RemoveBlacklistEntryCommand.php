<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Domain\Enum\BlacklistType;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class RemoveBlacklistEntryCommand implements WriteCommandInterface
{
    public function __construct(
        private BlacklistType $type,
        private string $value,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->removeBlacklistEntry($this->type, $this->value);

        return null;
    }
}
