<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Amp\DeferredFuture;
use Amp\Sync\Channel;
use Closure;
use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use InvalidArgumentException;
use Throwable;

use function Amp\async;

final class ReadWorkerPool
{
    /** @var list<Channel<mixed, mixed>> */
    private array $available;

    /** @var list<DeferredFuture<Channel<mixed, mixed>>> */
    private array $waiting = [];

    private int $liveChannels;

    /**
     * @param array<array-key, Channel<mixed, mixed>> $channels
     */
    public function __construct(
        array $channels,
        private readonly ?Closure $channelFactory = null,
    ) {
        if ([] === $channels) {
            throw new InvalidArgumentException('Read worker pool requires at least one channel');
        }

        $this->available = array_values($channels);
        $this->liveChannels = count($this->available);
    }

    public function query(ReadQueryInterface $query): mixed
    {
        $channel = $this->lease();

        try {
            $channel->send($query);
            $result = $channel->receive();
        } catch (Throwable $e) {
            $this->replaceChannel();

            throw $e;
        }

        $this->release($channel);

        if ($result instanceof WorkerFailure) {
            throw new WorkerResultException($result->getMessage());
        }

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $expectedType
     *
     * @return T
     */
    public function queryForInstance(ReadQueryInterface $query, string $expectedType): object
    {
        $result = $this->query($query);

        if (!$result instanceof $expectedType) {
            throw new WorkerResultException(sprintf('Read worker returned an unexpected result, expected %s', $expectedType));
        }

        return $result;
    }

    /**
     * @return list<mixed>
     */
    public function queryForList(ReadQueryInterface $query): array
    {
        $result = $this->query($query);

        if (!is_array($result)) {
            throw new WorkerResultException('Read worker returned an unexpected result, expected list');
        }

        return array_values($result);
    }

    private function replaceChannel(): void
    {
        $factory = $this->channelFactory;

        if (null === $factory) {
            $this->retireChannel();

            return;
        }

        async(function () use ($factory): void {
            try {
                $channel = $factory();
            } catch (Throwable) {
                $this->retireChannel();

                return;
            }

            $this->release($channel);
        });
    }

    private function retireChannel(): void
    {
        --$this->liveChannels;

        if (0 === $this->liveChannels) {
            $this->failWaiters();
        }
    }

    private function failWaiters(): void
    {
        $waiting = $this->waiting;
        $this->waiting = [];

        foreach ($waiting as $waiter) {
            $waiter->error(new WorkerResultException('Read worker pool exhausted: all channels failed and cannot be replaced'));
        }
    }

    /**
     * @return Channel<mixed, mixed>
     */
    private function lease(): Channel
    {
        $channel = array_shift($this->available);

        if ($channel instanceof Channel) {
            return $channel;
        }

        if (0 === $this->liveChannels) {
            throw new WorkerResultException('Read worker pool exhausted: all channels failed and cannot be replaced');
        }

        /** @var DeferredFuture<Channel<mixed, mixed>> $waiter */
        $waiter = new DeferredFuture();
        $this->waiting[] = $waiter;

        return $waiter->getFuture()->await();
    }

    /**
     * @param Channel<mixed, mixed> $channel
     */
    private function release(Channel $channel): void
    {
        $waiter = array_shift($this->waiting);

        if ($waiter instanceof DeferredFuture) {
            $waiter->complete($channel);

            return;
        }

        $this->available[] = $channel;
    }
}
