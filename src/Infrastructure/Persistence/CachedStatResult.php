<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

final readonly class CachedStatResult
{
    public function __construct(
        private int $computedAt,
        private string $payload,
    ) {
    }

    public function getComputedAt(): int
    {
        return $this->computedAt;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }
}
