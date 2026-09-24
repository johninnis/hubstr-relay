<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Fake;

use Amp\Sync\ChannelException;
use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\SettingKey;
use Innis\Hubstr\Relay\Infrastructure\Worker\Command\SaveSettingCommand;
use Innis\Hubstr\Relay\Tests\Fake\DirectWriteChannel;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DirectWriteChannelTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 3).'/resources/migrations');
    }

    public function testSendExecutesCommandAgainstConnection(): void
    {
        $channel = new DirectWriteChannel($this->pdo);

        $channel->send(new SaveSettingCommand(SettingKey::RelayName, 'Hubstr'));

        self::assertNull($channel->receive());

        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute(['relay_name']);
        self::assertSame('Hubstr', $statement->fetchColumn());
    }

    public function testSendRejectsValuesThatAreNotWriteCommands(): void
    {
        $channel = new DirectWriteChannel($this->pdo);

        $this->expectException(RuntimeException::class);

        $channel->send('not a command');
    }

    public function testReceiveThrowsWhenNoResultIsPending(): void
    {
        $channel = new DirectWriteChannel($this->pdo);

        $this->expectException(ChannelException::class);

        $channel->receive();
    }
}
