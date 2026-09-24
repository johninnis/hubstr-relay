<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Presentation\Cli;

use Innis\Hubstr\Relay\Application\Port\EventWriterInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\ImportEventUseCase;
use Innis\Hubstr\Relay\Domain\Enum\ImportOutcome;
use Innis\Hubstr\Relay\Presentation\Cli\ImportTally;
use Innis\Hubstr\Relay\Presentation\Cli\JsonlImporter;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonlImporterTest extends TestCase
{
    private const string EVENT_LINE = '{"id":"'.self::HEX_64.'","pubkey":"'.self::HEX_64.'","created_at":1,"kind":1,"tags":[],"content":"","sig":"'.self::HEX_128.'"}';
    private const string HEX_64 = 'abababababababababababababababababababababababababababababababab';
    private const string HEX_128 = self::HEX_64.self::HEX_64;

    public function testEveryLineIsTalliedByItsOutcomeAndBlankLinesAreCountedButNotImported(): void
    {
        $result = $this->importer(EventStoreOutcome::Stored)->import([self::EVENT_LINE, '', '   ', 'not json', self::EVENT_LINE."\n"], self::ignoreProgress(...));

        $this->assertSame(5, $result->getLinesRead());
        $this->assertSame('2 imported, 0 skipped, 1 failed', (string) $result->getTally());
    }

    public function testProgressIsReportedEveryThousandLines(): void
    {
        $reported = [];
        $lines = array_fill(0, 2001, self::EVENT_LINE);

        $this->importer(EventStoreOutcome::Duplicate)->import($lines, static function (int $linesRead, ImportTally $tally) use (&$reported): void {
            $reported[] = [$linesRead, $tally->count(ImportOutcome::Skipped)];
        });

        $this->assertSame([[1000, 1000], [2000, 2000]], $reported);
    }

    public function testAStoreFaultAbortsTheRunAtTheLineThatRaisedIt(): void
    {
        $writer = $this->createStub(EventWriterInterface::class);
        $writer->method('store')->willThrowException(new RuntimeException('database is locked'));

        $result = $this->importer($writer)->import(['not json', self::EVENT_LINE, self::EVENT_LINE], self::ignoreProgress(...));

        $this->assertSame('database is locked', $result->getAbortReason());
        $this->assertSame(2, $result->getLinesRead());
        $this->assertSame('0 imported, 0 skipped, 1 failed', (string) $result->getTally());
    }

    public function testARunIsCleanOnlyWhenItCompletedWithNoFailedLines(): void
    {
        $importer = $this->importer(EventStoreOutcome::Stored);

        $this->assertTrue($importer->import([self::EVENT_LINE, ''], self::ignoreProgress(...))->isClean());
        $this->assertFalse($importer->import([self::EVENT_LINE, 'not json'], self::ignoreProgress(...))->isClean());
    }

    private function importer(EventStoreOutcome|EventWriterInterface $store): JsonlImporter
    {
        if ($store instanceof EventStoreOutcome) {
            $outcome = $store;
            $store = $this->createStub(EventWriterInterface::class);
            $store->method('store')->willReturn($outcome);
        }

        $validator = $this->createStub(EventValidatorInterface::class);
        $validator->method('isEventValid')->willReturn(true);
        $policyState = $this->createStub(PolicyStateInterface::class);
        $policyState->method('isEventBlacklisted')->willReturn(false);

        return new JsonlImporter(new ImportEventUseCase($validator, $policyState, $store));
    }

    private static function ignoreProgress(int $linesRead, ImportTally $tally): void
    {
    }
}
