<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Worker;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Domain\ValueObject\StatKey;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SettingKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\AddTenantCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\DeletePubkeyChunkCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\PersistStatResultCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\SaveSettingCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\StoreEventsCommand;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use PDO;
use PHPUnit\Framework\TestCase;

final class WriteContextTest extends TestCase
{
    private PDO $pdo;
    private WriteContext $context;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
        $this->context = WriteContext::forConnection($this->pdo);
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    public function testStoreEventsCommandApplies(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'hello');

        $result = new StoreEventsCommand(new EventCollection([$event]))->applyTo($this->context);

        self::assertSame([EventStoreOutcome::Stored], $result);
    }

    public function testDeletePubkeyChunkCommandApplies(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::TEXT_NOTE), 'spam');
        new StoreEventsCommand(new EventCollection([$event]))->applyTo($this->context);

        $result = new DeletePubkeyChunkCommand($event->getPubkey(), 100)->applyTo($this->context);

        self::assertSame(1, $result);
    }

    public function testAddTenantCommandApplies(): void
    {
        new AddTenantCommand(SignedEventFactory::pubkey('aa'))->applyTo($this->context);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tenants');
        $statement->execute();
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testSaveSettingCommandApplies(): void
    {
        new SaveSettingCommand(SettingKey::RelayName, 'Hubstr')->applyTo($this->context);

        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute(['relay_name']);
        self::assertSame('Hubstr', $statement->fetchColumn());
    }

    public function testPersistStatResultCommandApplies(): void
    {
        new PersistStatResultCommand(StatKey::totals(), '{"events":1}')->applyTo($this->context);

        $statement = $this->pdo->prepare('SELECT payload FROM stat_results WHERE stat_name = ?');
        $statement->execute(['totals']);
        self::assertSame('{"events":1}', $statement->fetchColumn());
    }
}
