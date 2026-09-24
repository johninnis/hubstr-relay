<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Command;

use Innis\Hubstr\Relay\Infrastructure\Worker\ChunkedDeleteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class DeletePubkeyChunkCommand implements ChunkedDeleteCommandInterface
{
    public function __construct(
        private PublicKey $pubkey,
        private int $chunkSize,
    ) {
    }

    #[Override]
    public function applyTo(WriteContext $context): mixed
    {
        return $context->getEventWriteStore()->deleteByPubkeyChunk($this->pubkey, $this->chunkSize);
    }

    #[Override]
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}
