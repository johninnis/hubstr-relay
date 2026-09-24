<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Cli;

use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Override;
use Stringable;

final readonly class ImportTally implements Stringable
{
    private function __construct(
        private int $imported,
        private int $skipped,
        private int $failed,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    public function withOutcome(ImportOutcome $outcome): self
    {
        return match ($outcome) {
            ImportOutcome::Imported => new self($this->imported + 1, $this->skipped, $this->failed),
            ImportOutcome::Skipped => new self($this->imported, $this->skipped + 1, $this->failed),
            ImportOutcome::Failed => new self($this->imported, $this->skipped, $this->failed + 1),
        };
    }

    public function count(ImportOutcome $outcome): int
    {
        return match ($outcome) {
            ImportOutcome::Imported => $this->imported,
            ImportOutcome::Skipped => $this->skipped,
            ImportOutcome::Failed => $this->failed,
        };
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    #[Override]
    public function __toString(): string
    {
        return sprintf('%d imported, %d skipped, %d failed', $this->imported, $this->skipped, $this->failed);
    }
}
