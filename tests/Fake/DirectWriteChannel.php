<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Closure;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;
use PDO;
use RuntimeException;

/**
 * @implements Channel<mixed, mixed>
 */
final class DirectWriteChannel implements Channel
{
    private readonly WriteContext $context;

    /** @var list<mixed> */
    private array $results = [];

    private bool $closed = false;

    public function __construct(PDO $pdo)
    {
        $this->context = WriteContext::forConnection($pdo);
    }

    #[Override]
    public function send(mixed $data): void
    {
        if (!$data instanceof WriteCommandInterface) {
            throw new RuntimeException('DirectWriteChannel only supports WriteCommandInterface');
        }

        $this->results[] = $data->applyTo($this->context);
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        if ([] === $this->results) {
            throw new ChannelException('DirectWriteChannel has no pending result');
        }

        return array_shift($this->results);
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
