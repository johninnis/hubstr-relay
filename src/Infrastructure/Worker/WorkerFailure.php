<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker;

use Throwable;

final readonly class WorkerFailure
{
    public function __construct(
        private string $message,
    ) {
    }

    public static function fromThrowable(Throwable $error): self
    {
        return new self($error::class.': '.$error->getMessage());
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
