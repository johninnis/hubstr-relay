<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Closure;
use Override;

/**
 * @implements Channel<mixed, mixed>
 */
final class QueueChannel implements Channel
{
    /** @var list<mixed> */
    public array $sent = [];

    /** @var list<mixed> */
    private array $inbox;

    private bool $closed = false;

    /**
     * @param list<mixed> $inbox
     */
    public function __construct(array $inbox = [])
    {
        $this->inbox = $inbox;
    }

    #[Override]
    public function send(mixed $data): void
    {
        $this->sent[] = $data;
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        if ([] === $this->inbox) {
            throw new ChannelException('QueueChannel inbox exhausted');
        }

        return array_shift($this->inbox);
    }

    #[Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }

    #[Override]
    public function onClose(Closure $onClose): void
    {
    }
}
