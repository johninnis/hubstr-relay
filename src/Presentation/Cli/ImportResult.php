<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Cli;

final readonly class ImportResult
{
    private function __construct(
        private ImportTally $tally,
        private int $linesRead,
        private ?string $abortReason,
    ) {
    }

    public static function completed(ImportTally $tally, int $linesRead): self
    {
        return new self($tally, $linesRead, null);
    }

    public static function aborted(ImportTally $tally, int $atLine, string $reason): self
    {
        return new self($tally, $atLine, $reason);
    }

    public function getTally(): ImportTally
    {
        return $this->tally;
    }

    public function getLinesRead(): int
    {
        return $this->linesRead;
    }

    public function getAbortReason(): ?string
    {
        return $this->abortReason;
    }

    public function isClean(): bool
    {
        return null === $this->abortReason && !$this->tally->hasFailures();
    }
}
