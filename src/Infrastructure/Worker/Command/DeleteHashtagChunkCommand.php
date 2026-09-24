<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\ChunkedDeleteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Override;

final readonly class DeleteHashtagChunkCommand implements ChunkedDeleteCommandInterface
{
    public function __construct(
        private Hashtag $hashtag,
        private int $chunkSize,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteByHashtagChunk($this->hashtag, $this->chunkSize);
    }

    #[Override]
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}
