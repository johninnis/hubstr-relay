<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Cli;

use Innis\Hubstr\Relay\Application\UseCase\ImportEventUseCase;
use Throwable;

final readonly class JsonlImporter
{
    private const int PROGRESS_EVERY_LINES = 1000;

    public function __construct(
        private ImportEventUseCase $useCase,
    ) {
    }

    /**
     * @param iterable<string>                 $lines
     * @param callable(int, ImportTally): void $onProgress
     */
    public function import(iterable $lines, callable $onProgress): ImportResult
    {
        $tally = ImportTally::empty();
        $linesRead = 0;

        foreach ($lines as $line) {
            ++$linesRead;
            $trimmed = trim($line);

            if ('' !== $trimmed) {
                try {
                    $tally = $tally->withOutcome($this->useCase->import($trimmed));
                } catch (Throwable $fault) {
                    return ImportResult::aborted($tally, $linesRead, $fault->getMessage());
                }
            }

            if (0 === $linesRead % self::PROGRESS_EVERY_LINES) {
                $onProgress($linesRead, $tally);
            }
        }

        return ImportResult::completed($tally, $linesRead);
    }
}
