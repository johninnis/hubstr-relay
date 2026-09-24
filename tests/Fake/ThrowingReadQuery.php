<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Override;
use RuntimeException;

final readonly class ThrowingReadQuery implements ReadQueryInterface
{
    public function __construct(
        private string $message,
    ) {
    }

    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        throw new RuntimeException($this->message);
    }
}
