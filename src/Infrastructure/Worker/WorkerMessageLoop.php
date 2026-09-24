<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Amp\Cancellation;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use Throwable;

final readonly class WorkerMessageLoop
{
    /**
     * @param Channel<mixed, mixed> $channel
     */
    public function __construct(
        private Channel $channel,
        private Cancellation $cancellation,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T>    $accepts
     * @param callable(T): mixed $handle
     */
    public function process(string $accepts, callable $handle): void
    {
        while (true) {
            try {
                $message = $this->channel->receive($this->cancellation);
            } catch (ChannelException) {
                return;
            }

            // Deliberate: a message that is not a command ends the loop, and the worker says so before it goes — see ADR-0011
            if (!$message instanceof $accepts) {
                $this->channel->send(new WorkerFailure(sprintf(
                    'Worker expected %s but received %s',
                    $accepts,
                    get_debug_type($message),
                )));

                return;
            }

            try {
                $result = $handle($message);
            } catch (Throwable $error) {
                $this->channel->send(WorkerFailure::fromThrowable($error));

                continue;
            }

            $this->channel->send($result);
        }
    }
}
