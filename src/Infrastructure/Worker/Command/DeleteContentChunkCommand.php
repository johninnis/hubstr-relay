<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Infrastructure\Worker\ChunkedDeleteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;

final readonly class DeleteContentChunkCommand implements ChunkedDeleteCommandInterface
{
    public function __construct(
        private BlacklistWord $word,
        private int $chunkSize,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteByContentMatchChunk($this->word, $this->chunkSize);
    }

    #[Override]
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}
