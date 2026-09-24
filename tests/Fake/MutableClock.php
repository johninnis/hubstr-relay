<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Override;

final class MutableClock implements ClockInterface
{
    public function __construct(private int $now = 1_000_000)
    {
    }

    #[Override]
    public function now(): Timestamp
    {
        return Timestamp::fromInt($this->now);
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
