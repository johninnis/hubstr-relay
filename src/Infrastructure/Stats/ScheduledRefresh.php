<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Stats;

use Amp\Parallel\Worker\Task;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;

final readonly class ScheduledRefresh
{
    /**
     * @param Task<PersistStatResultCommand, mixed, mixed> $task
     */
    public function __construct(
        private string $label,
        private Task $task,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return Task<PersistStatResultCommand, mixed, mixed>
     */
    public function getTask(): Task
    {
        return $this->task;
    }
}
