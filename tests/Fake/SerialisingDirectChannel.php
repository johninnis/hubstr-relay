<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Closure;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCommandInterface;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Override;
use PDO;
use RuntimeException;

/**
 * @implements Channel<mixed, mixed>
 */
final class SerialisingDirectChannel implements Channel
{
    private readonly WriteContext $writeContext;

    private readonly ReadContext $readContext;

    /** @var list<mixed> */
    private array $results = [];

    private bool $closed = false;

    public function __construct(PDO $pdo)
    {
        $this->writeContext = WriteContext::forConnection($pdo);
        $this->readContext = ReadContext::forConnection($pdo);
    }

    #[Override]
    public function send(mixed $data): void
    {
        $work = unserialize(serialize($data));

        $result = match (true) {
            $work instanceof WriteCommandInterface => $work->applyTo($this->writeContext),
            $work instanceof ReadQueryInterface => $work->applyTo($this->readContext),
            default => throw new RuntimeException('SerialisingDirectChannel only supports write commands and read queries'),
        };

        $this->results[] = unserialize(serialize($result));
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        if ([] === $this->results) {
            throw new ChannelException('SerialisingDirectChannel has no pending result');
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
