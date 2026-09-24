<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Sync\Channel;
use Closure;
use LogicException;
use Override;

/**
 * @implements Channel<mixed, mixed>
 */
final class ControllableChannel implements Channel
{
    /** @var list<mixed> */
    public array $sent = [];

    /** @var list<DeferredFuture<mixed>> */
    private array $pendingReceives = [];

    #[Override]
    public function send(mixed $data): void
    {
        $this->sent[] = $data;
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        $deferred = new DeferredFuture();
        $this->pendingReceives[] = $deferred;

        return $deferred->getFuture()->await();
    }

    public function answer(mixed $value): void
    {
        $deferred = array_shift($this->pendingReceives)
            ?? throw new LogicException('No pending receive to answer');

        $deferred->complete($value);
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
