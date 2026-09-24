<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;
use RuntimeException;

final readonly class ThrowingWriteCommand implements WriteCommandInterface
{
    public function __construct(
        private string $message,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        throw new RuntimeException($this->message);
    }
}
