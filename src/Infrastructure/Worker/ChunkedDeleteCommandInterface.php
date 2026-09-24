<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

// Deliberate: each chunked delete carries its own chunk size and decides for itself when it has finished — see ADR-0030
interface ChunkedDeleteCommandInterface extends WriteCommandInterface
{
    public function getChunkSize(): int;
}
