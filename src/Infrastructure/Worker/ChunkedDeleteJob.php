<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

final readonly class ChunkedDeleteJob
{
    public function __construct(
        private ChunkedDeleteCommandInterface $chunkCommand,
        private string $description,
        private int $totalDeleted = 0,
    ) {
    }

    public function isCompletedBy(int $deleted): bool
    {
        return $deleted < $this->chunkCommand->getChunkSize();
    }

    public function getChunkCommand(): ChunkedDeleteCommandInterface
    {
        return $this->chunkCommand;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTotalDeleted(): int
    {
        return $this->totalDeleted;
    }

    public function withChunk(int $deleted): self
    {
        return new self($this->chunkCommand, $this->description, $this->totalDeleted + $deleted);
    }
}
