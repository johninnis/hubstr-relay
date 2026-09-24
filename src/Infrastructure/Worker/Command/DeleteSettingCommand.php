<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Persistence\SettingKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class DeleteSettingCommand implements WriteCommandInterface
{
    public function __construct(
        private SettingKey $key,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->deleteSetting($this->key);

        return null;
    }
}
