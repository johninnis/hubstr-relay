<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Presentation\Cli;

use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Hubstr\Relay\Presentation\Cli\ImportTally;
use PHPUnit\Framework\TestCase;

final class ImportTallyTest extends TestCase
{
    public function testEachOutcomeIsCountedSeparately(): void
    {
        $tally = ImportTally::empty()
            ->withOutcome(ImportOutcome::Imported)
            ->withOutcome(ImportOutcome::Imported)
            ->withOutcome(ImportOutcome::Skipped)
            ->withOutcome(ImportOutcome::Failed);

        $this->assertSame('2 imported, 1 skipped, 1 failed', (string) $tally);
    }

    public function testATallyWithNoFailedLinesHasNoFailures(): void
    {
        $this->assertFalse(ImportTally::empty()->withOutcome(ImportOutcome::Skipped)->hasFailures());
    }

    public function testATallyWithAFailedLineHasFailures(): void
    {
        $this->assertTrue(ImportTally::empty()->withOutcome(ImportOutcome::Failed)->hasFailures());
    }

    public function testWithOutcomeReturnsANewTally(): void
    {
        $empty = ImportTally::empty();
        $empty->withOutcome(ImportOutcome::Imported);

        $this->assertSame(0, $empty->count(ImportOutcome::Imported));
    }
}
