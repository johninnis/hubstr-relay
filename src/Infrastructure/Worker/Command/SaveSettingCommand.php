<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Persistence\SettingKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class SaveSettingCommand implements WriteCommandInterface
{
    public function __construct(
        private SettingKey $key,
        private string $value,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getPolicyWriteStore()->saveSetting($this->key, $this->value);

        return null;
    }
}
