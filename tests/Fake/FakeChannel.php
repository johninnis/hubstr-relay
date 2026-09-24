<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Closure;
use LogicException;
use Override;

/**
 * @template TSend
 * @template TReceive
 *
 * @implements Channel<TSend, TReceive>
 */
final class FakeChannel implements Channel
{
    #[Override]
    public function send(mixed $data): void
    {
        throw new LogicException('FakeChannel cannot send');
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        throw new LogicException('FakeChannel cannot receive');
    }

    #[Override]
    public function close(): void
    {
    }

    #[Override]
    public function isClosed(): bool
    {
        return false;
    }

    #[Override]
    public function onClose(Closure $onClose): void
    {
    }
}
