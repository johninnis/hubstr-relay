<?php

declare(strict_types=1);

use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\Presentation\Cli\ExportOptionFailure;
use Innis\Hubstr\Relay\Presentation\Cli\ExportOptions;
use Innis\Hubstr\Relay\RelayContainer;

require_once dirname(__DIR__).'/vendor/autoload.php';

$criteria = ExportOptions::toCriteria(getopt('', ExportOptions::LONG_OPTIONS) ?: []);

if ($criteria instanceof ExportOptionFailure) {
    fwrite(STDERR, $criteria->getMessage()."\n");
    exit(2);
}

$useCase = new RelayContainer(RelayConfig::load(dirname(__DIR__).'/config/relay.php'))->exportEventsUseCase();

$count = 0;
foreach ($useCase->export($criteria) as $json) {
    fwrite(STDOUT, $json."\n");
    ++$count;
}

fwrite(STDERR, "Exported {$count} events\n");
