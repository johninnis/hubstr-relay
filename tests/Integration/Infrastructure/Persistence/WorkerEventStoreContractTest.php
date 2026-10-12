<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WorkerEventStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadWorkerPool;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteCoordinator;
use Innis\Hubstr\Relay\Tests\Fake\SerialisingDirectChannel;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContract;
use Innis\Nostr\Relay\Tests\Contract\RelayEventStoreContractCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class WorkerEventStoreContractTest extends TestCase
{
    /**
     * @return array<string, array{RelayEventStoreContractCase}>
     */
    public static function contract(): array
    {
        return RelayEventStoreContract::cases();
    }

    #[DataProvider('contract')]
    public function testTheSqliteStoreKeepsTheRelayEventStoreContract(RelayEventStoreContractCase $case): void
    {
        $store = self::freshStore();

        $this->assertSame($case->getExpected(), async(static fn (): array|int => $case->observe($store))->await());
    }

    private static function freshStore(): WorkerEventStore
    {
        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        return new WorkerEventStore(
            new WriteCoordinator(new SerialisingDirectChannel($pdo)),
            new ReadWorkerPool([new SerialisingDirectChannel($pdo)]),
            1000,
        );
    }
}
