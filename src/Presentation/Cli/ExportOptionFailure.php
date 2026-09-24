<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Cli;

final readonly class ExportOptionFailure
{
    public function __construct(
        private string $option,
        private string $value,
    ) {
    }

    public function getMessage(): string
    {
        return sprintf('Invalid --%s: %s', $this->option, '' === $this->value ? 'expected a single value' : $this->value);
    }
}
