<?php

declare(strict_types=1);

use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\Presentation\Cli\ImportTally;
use Innis\Hubstr\Relay\Presentation\Cli\JsonlImporter;
use Innis\Hubstr\Relay\RelayContainer;

require_once dirname(__DIR__).'/vendor/autoload.php';

$useCase = new RelayContainer(RelayConfig::load(dirname(__DIR__).'/config/relay.php'))->importEventUseCase();

$startTime = microtime(true);
$elapsed = static fn (): float => microtime(true) - $startTime;

$stdinLines = static function (): Generator {
    while (false !== ($line = fgets(STDIN))) {
        yield $line;
    }
};

$result = new JsonlImporter($useCase)->import($stdinLines(), static function (int $linesRead, ImportTally $tally) use ($elapsed): void {
    fwrite(STDERR, sprintf("\rProcessed %d lines: %s (%.1fs)", $linesRead, $tally, $elapsed()));
});

$abortReason = $result->getAbortReason();

fwrite(STDERR, sprintf("\n%s: %s (%.1fs)\n", null === $abortReason ? 'Done' : 'Stopped', $result->getTally(), $elapsed()));

if (null !== $abortReason) {
    fwrite(STDERR, sprintf("Aborted at line %d: %s\n", $result->getLinesRead(), $abortReason));
}

exit($result->isClean() ? 0 : 1);
