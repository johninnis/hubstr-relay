<?php

declare(strict_types=1);

use Innis\Hubstr\Core\Application\Service\Kernel;
use Innis\Hubstr\Core\Infrastructure\Logging\LoggerFactory;
use Innis\Hubstr\Core\Infrastructure\Process\AmphpShutdownSignal;
use Innis\Hubstr\Relay\Infrastructure\Config\RelayConfig;
use Innis\Hubstr\Relay\RelayContainer;

use function Amp\ByteStream\getStdout;

require_once dirname(__DIR__).'/vendor/autoload.php';

$config = RelayConfig::load(dirname(__DIR__).'/config/relay.php');
$logger = new LoggerFactory(getStdout(), $config->getRuntime()->getLogLevel())->create('relay');

$container = new RelayContainer($config, $logger);
$container->bootstrap();
$server = $container->server();

new Kernel($logger, new AmphpShutdownSignal(), $container->lifecycle())->run($server, 'Hubstr Relay started', [
    'relay_url' => (string) $config->getRelayUrl(),
    'database' => $config->getRuntime()->getDatabasePath(),
]);
