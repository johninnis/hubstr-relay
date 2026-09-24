<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Amp\DeferredFuture;
use Amp\Sync\Channel;
use Innis\Hubstr\Relay\Application\Port\EventPurgerInterface;
use Innis\Hubstr\Relay\Domain\Exception\WorkerResultException;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteContentChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeleteHashtagChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeletePubkeyChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PurgeExpiredChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\StoreEventsCommand;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;

final class WriteCoordinator implements EventPurgerInterface
{
    public const int CHUNK_SIZE = 500;

    // Deliberate: far larger than the indexed chunks, because a content purge pays one full table scan per chunk and the chunk size is what amortises it — see ADR-0030
    private const int CONTENT_CHUNK_SIZE = 50_000;

    public const int MAX_BATCH = 64;

    /** @var list<Event> */
    private array $bufferedEvents = [];

    /** @var list<DeferredFuture<mixed>> */
    private array $bufferedStoreFutures = [];

    /** @var list<WriteCommandInterface> */
    private array $pendingCommands = [];

    /** @var list<DeferredFuture<mixed>> */
    private array $pendingFutures = [];

    /** @var list<ChunkedDeleteJob> */
    private array $deleteJobs = [];

    /** @var ?DeferredFuture<null> */
    private ?DeferredFuture $idle = null;

    private bool $draining = false;

    private int $batchesSinceDelete = 0;

    /**
     * @param Channel<mixed, mixed> $channel
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxBatchesBeforeDelete = 8,
    ) {
    }

    public function store(Event $event): EventStoreOutcome
    {
        $deferred = new DeferredFuture();
        $this->bufferedEvents[] = $event;
        $this->bufferedStoreFutures[] = $deferred;
        $this->ensureDraining();

        $outcome = $deferred->getFuture()->await();

        if (!$outcome instanceof EventStoreOutcome) {
            throw new WorkerResultException('Write worker returned an unexpected store result');
        }

        return $outcome;
    }

    public function apply(WriteCommandInterface $command): void
    {
        $this->dispatch($command);
    }

    public function applyForInt(WriteCommandInterface $command): int
    {
        return $this->expectInt($this->dispatch($command));
    }

    #[Override]
    public function purgeByPubkey(PublicKey $pubkey): void
    {
        $this->enqueueChunkedDelete(
            new DeletePubkeyChunkCommand($pubkey, self::CHUNK_SIZE),
            'pubkey '.$pubkey->toHex(),
        );
    }

    #[Override]
    public function purgeByContentMatch(BlacklistWord $word): void
    {
        $this->enqueueChunkedDelete(
            new DeleteContentChunkCommand($word, self::CONTENT_CHUNK_SIZE),
            'word '.$word,
        );
    }

    #[Override]
    public function purgeByHashtag(Hashtag $hashtag): void
    {
        $this->enqueueChunkedDelete(
            new DeleteHashtagChunkCommand($hashtag, self::CHUNK_SIZE),
            'hashtag '.$hashtag,
        );
    }

    // Deliberate: the retention sweep reuses the chunked delete jobs a ban already runs on, and never queues a second sweep behind an unfinished one — see ADR-0028
    #[Override]
    public function purgeExpired(): void
    {
        $sweeping = array_any(
            $this->deleteJobs,
            static fn (ChunkedDeleteJob $job): bool => $job->getChunkCommand() instanceof PurgeExpiredChunkCommand,
        );

        if ($sweeping) {
            return;
        }

        $this->enqueueChunkedDelete(
            new PurgeExpiredChunkCommand(self::CHUNK_SIZE),
            'expired events',
        );
    }

    private function dispatch(WriteCommandInterface $command): mixed
    {
        $deferred = new DeferredFuture();
        $this->pendingCommands[] = $command;
        $this->pendingFutures[] = $deferred;
        $this->ensureDraining();

        return $deferred->getFuture()->await();
    }

    private function expectInt(mixed $result): int
    {
        if (!is_int($result)) {
            throw new WorkerResultException('Write worker returned an unexpected result, expected int');
        }

        return $result;
    }

    private function enqueueChunkedDelete(ChunkedDeleteCommandInterface $chunkCommand, string $description): void
    {
        $this->deleteJobs[] = new ChunkedDeleteJob($chunkCommand, $description);
        $this->ensureDraining();
    }

    private function ensureDraining(): void
    {
        if (null !== $this->idle) {
            $idle = $this->idle;
            $this->idle = null;
            $idle->complete();
        }

        if (!$this->draining) {
            $this->draining = true;
            async($this->drain(...));
        }
    }

    private function drain(): void
    {
        while (!$this->channel->isClosed()) {
            $hasEvents = [] !== $this->bufferedEvents;
            $hasDeleteWork = [] !== $this->pendingCommands || [] !== $this->deleteJobs;

            if (!$hasEvents && !$hasDeleteWork) {
                $this->idle = new DeferredFuture();
                $this->idle->getFuture()->await();

                continue;
            }

            if ($hasEvents && (!$hasDeleteWork || $this->batchesSinceDelete < $this->maxBatchesBeforeDelete)) {
                $this->flushEvents();

                if ($hasDeleteWork) {
                    ++$this->batchesSinceDelete;
                }

                continue;
            }

            $this->batchesSinceDelete = 0;

            if ([] !== $this->pendingCommands) {
                $this->flushPending();
            } else {
                $this->flushDeleteChunk();
            }
        }

        $this->draining = false;
        $this->failOutstanding(new WorkerResultException('Write worker channel closed'));
    }

    private function failOutstanding(Throwable $error): void
    {
        $this->failAll($this->bufferedStoreFutures, $error);
        $this->bufferedEvents = [];
        $this->bufferedStoreFutures = [];

        $this->failAll($this->pendingFutures, $error);
        $this->pendingCommands = [];
        $this->pendingFutures = [];

        foreach ($this->deleteJobs as $job) {
            $this->logger->error('Chunked deletion abandoned: write worker channel closed', [
                'target' => $job->getDescription(),
            ]);
        }
        $this->deleteJobs = [];
    }

    private function flushEvents(): void
    {
        $events = array_splice($this->bufferedEvents, 0, self::MAX_BATCH);
        $futures = array_splice($this->bufferedStoreFutures, 0, self::MAX_BATCH);

        try {
            $result = $this->roundTrip(new StoreEventsCommand(new EventCollection($events)));
        } catch (Throwable $e) {
            $this->failAll($futures, $e);

            return;
        }

        if (!is_array($result)) {
            $this->failAll($futures, new WorkerResultException('Write worker returned a non-array store result'));

            return;
        }

        foreach ($futures as $index => $future) {
            $outcome = $result[$index] ?? null;

            if ($outcome instanceof EventStoreOutcome) {
                $future->complete($outcome);
            } elseif ($outcome instanceof WorkerFailure) {
                $future->error(new WorkerResultException($outcome->getMessage()));
            } else {
                $future->error(new WorkerResultException('Write worker returned an unexpected store result'));
            }
        }
    }

    private function flushPending(): void
    {
        $command = array_shift($this->pendingCommands);
        $future = array_shift($this->pendingFutures);

        if (!$command instanceof WriteCommandInterface || !$future instanceof DeferredFuture) {
            return;
        }

        try {
            $result = $this->roundTrip($command);
        } catch (Throwable $e) {
            $future->error($e);

            return;
        }

        $future->complete($result);
    }

    private function flushDeleteChunk(): void
    {
        $job = $this->deleteJobs[0] ?? null;

        if (!$job instanceof ChunkedDeleteJob) {
            return;
        }

        try {
            $deleted = $this->expectInt($this->roundTrip($job->getChunkCommand()));
        } catch (Throwable $e) {
            array_shift($this->deleteJobs);
            $this->logger->error('Chunked deletion failed', [
                'target' => $job->getDescription(),
                'exception' => $e,
            ]);

            return;
        }

        $job = $job->withChunk($deleted);
        $this->deleteJobs[0] = $job;

        if ($job->isCompletedBy($deleted)) {
            array_shift($this->deleteJobs);

            // Deliberate: a chunked delete that removed nothing is not reported — see ADR-0028
            if ($job->getTotalDeleted() > 0) {
                $this->logger->info('Chunked deletion complete', [
                    'target' => $job->getDescription(),
                    'deleted' => $job->getTotalDeleted(),
                ]);
            }
        }
    }

    private function roundTrip(WriteCommandInterface $command): mixed
    {
        $this->channel->send($command);
        $result = $this->channel->receive();

        if ($result instanceof WorkerFailure) {
            throw new WorkerResultException($result->getMessage());
        }

        return $result;
    }

    /**
     * @param list<DeferredFuture<mixed>> $futures
     */
    private function failAll(array $futures, Throwable $error): void
    {
        foreach ($futures as $future) {
            $future->error($error);
        }
    }
}
