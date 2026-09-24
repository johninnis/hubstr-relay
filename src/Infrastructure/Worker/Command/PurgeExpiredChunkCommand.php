<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\ChunkedDeleteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class PurgeExpiredChunkCommand implements ChunkedDeleteCommandInterface
{
    public function __construct(
        private int $chunkSize,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteExpiredChunk($context->getClock()->now(), $this->chunkSize);
    }

    #[Override]
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}
