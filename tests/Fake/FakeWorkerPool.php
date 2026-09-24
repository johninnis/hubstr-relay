<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Fake;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use LogicException;
use Override;
use Throwable;

final class FakeWorkerPool implements WorkerPool
{
    /** @var list<Task<mixed, mixed, mixed>> */
    public array $submitted = [];

    public bool $killed = false;

    /** @var ?DeferredFuture<mixed> */
    private ?DeferredFuture $pending = null;

    /**
     * @template TResult
     * @template TReceive
     * @template TSend
     *
     * @param Task<TResult, TReceive, TSend> $task
     *
     * @return Execution<TResult, TReceive, TSend>
     */
    #[Override]
    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        $this->submitted[] = $task;

        /** @var DeferredFuture<TResult> $deferred */
        $deferred = new DeferredFuture();
        $this->pending = $deferred;

        /** @var FakeChannel<TSend, TReceive> $channel */
        $channel = new FakeChannel();

        return new Execution($task, $channel, $deferred->getFuture());
    }

    public function completePending(mixed $value = null): void
    {
        $deferred = $this->pending ?? throw new LogicException('No pending task');
        $this->pending = null;
        $deferred->complete($value);
    }

    public function failPending(Throwable $error): void
    {
        $deferred = $this->pending ?? throw new LogicException('No pending task');
        $this->pending = null;
        $deferred->error($error);
    }

    #[Override]
    public function isRunning(): bool
    {
        return true;
    }

    #[Override]
    public function isIdle(): bool
    {
        return null === $this->pending;
    }

    #[Override]
    public function shutdown(): void
    {
    }

    #[Override]
    public function kill(): void
    {
        $this->killed = true;
    }

    #[Override]
    public function getWorker(): Worker
    {
        throw new LogicException('FakeWorkerPool does not expose workers');
    }

    #[Override]
    public function getWorkerCount(): int
    {
        return 1;
    }

    #[Override]
    public function getIdleWorkerCount(): int
    {
        return $this->isIdle() ? 1 : 0;
    }
}
