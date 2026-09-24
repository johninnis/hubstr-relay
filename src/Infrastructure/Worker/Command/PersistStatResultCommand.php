<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\CachedStatResult;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class PersistStatResultCommand implements WriteCommandInterface
{
    public function __construct(
        private StatKey $key,
        private string $payload,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        $context->getStatResultsStore()->save(
            $this->key,
            new CachedStatResult($context->getClock()->now()->toInt(), $this->payload),
        );

        return null;
    }
}
