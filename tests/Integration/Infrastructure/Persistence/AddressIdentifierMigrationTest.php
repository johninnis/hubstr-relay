<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use PDO;
use PHPUnit\Framework\TestCase;

final class AddressIdentifierMigrationTest extends TestCase
{
    private PDO $pdo;
    private KeyPair $keyPair;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate($this->migrationsUpTo(1));
        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            array_map(unlink(...), glob($directory.'/*.sql') ?: []);
            rmdir($directory);
        }
    }

    public function testAMetadataRowIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::METADATA, [['d', 'ignored']]);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAKindAtTheStartOfTheReplaceableRangeIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(10000, [['d', 'ignored']]);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAKindAtTheEndOfTheReplaceableRangeIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(19999, []);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowIsFiledUnderItsDTag(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', 'café ☕']]);

        self::assertSame('café ☕', $this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowWithAgreeingDTagsIsFiledUnderThatValue(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', 'same'], ['d', 'same']]);

        self::assertSame('same', $this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowWithDisagreeingDTagsHasNoIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', 'first'], ['d', 'second']]);

        self::assertNull($this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowWithoutADTagIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['t', 'unrelated']]);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowWhoseDTagCarriesNoValueIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d']]);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAnAddressableRowWithAnEmptyDTagIsFiledUnderTheEmptyIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', '']]);

        self::assertSame('', $this->addressIdentifierOf($event));
    }

    public function testAKindAtTheEndOfTheAddressableRangeIsFiledUnderItsDTag(): void
    {
        $event = $this->storedBeforeMigration(39999, [['d', 'edge']]);

        self::assertSame('edge', $this->addressIdentifierOf($event));
    }

    public function testARegularRowHasNoIdentifier(): void
    {
        $event = $this->storedBeforeMigration(EventKind::TEXT_NOTE, [['d', 'ignored']]);

        self::assertNull($this->addressIdentifierOf($event));
    }

    public function testAnEphemeralRowHasNoIdentifier(): void
    {
        $event = $this->storedBeforeMigration(20000, [['d', 'ignored']]);

        self::assertNull($this->addressIdentifierOf($event));
    }

    public function testAKindAboveTheAddressableRangeHasNoIdentifier(): void
    {
        $event = $this->storedBeforeMigration(40000, [['d', 'ignored']]);

        self::assertNull($this->addressIdentifierOf($event));
    }

    public function testTheMigrationLeavesRawEventUntouched(): void
    {
        $raw = json_encode([...$this->signedAt(EventKind::LONGFORM_CONTENT, [['d', 'first']], 100)->toArray(), 'evil' => 'unsigned'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $event = $this->signedAt(EventKind::LONGFORM_CONTENT, [['d', 'first']], 100);
        $this->insertLegacy($event, $raw);

        $this->migrateFully();

        $statement = $this->pdo->prepare('SELECT raw_event FROM events WHERE event_id = ?');
        $statement->execute([$event->getId()->toBytes()]);
        self::assertSame($raw, $statement->fetchColumn());
    }

    public function testABackfilledRowIsReplacedThroughItsDTag(): void
    {
        $older = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', 'first']]);

        $newer = $this->signedAt(EventKind::LONGFORM_CONTENT, [['d', 'first']], 200);
        WriteContext::forConnection($this->pdo)->getEventWriteStore()->store($newer);

        self::assertFalse($this->rawEventOf($older));
    }

    public function testABackfilledRowWhoseDTagsDisagreeIsNotReplacedThroughEither(): void
    {
        $older = $this->storedBeforeMigration(EventKind::LONGFORM_CONTENT, [['d', 'first'], ['d', 'second']]);

        $newer = $this->signedAt(EventKind::LONGFORM_CONTENT, [['d', 'first']], 200);
        WriteContext::forConnection($this->pdo)->getEventWriteStore()->store($newer);

        self::assertNotFalse($this->rawEventOf($older));
    }

    public function testAFreshDatabaseGetsTheColumnAndTheIndex(): void
    {
        $pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $columns = $pdo->query("SELECT COUNT(*) FROM pragma_table_info('events') WHERE name = 'address_identifier'");
        $indices = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_events_address'");

        self::assertSame(1, (int) (false === $columns ? 0 : $columns->fetchColumn()));
        self::assertSame(1, (int) (false === $indices ? 0 : $indices->fetchColumn()));
    }

    /**
     * @param list<list<string>> $tags
     */
    private function storedBeforeMigration(int $kind, array $tags): Event
    {
        $event = $this->signedAt($kind, $tags, 100);
        $this->insertLegacy($event, $event->toJson());
        $this->migrateFully();

        return $event;
    }

    private function insertLegacy(Event $event, string $rawEvent): void
    {
        $this->pdo->prepare('INSERT INTO events (event_id, pubkey, kind, created_at, content, raw_event) VALUES (?, ?, ?, ?, ?, ?)')->execute([
            $event->getId()->toBytes(),
            $event->getPubkey()->toBytes(),
            $event->getKind()->toInt(),
            $event->getCreatedAt()->toInt(),
            (string) $event->getContent(),
            $rawEvent,
        ]);
    }

    private function migrateFully(): void
    {
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');
    }

    /**
     * @param list<list<string>> $tags
     */
    private function signedAt(int $kind, array $tags, int $createdAt): Event
    {
        $rumour = Rumour::tryFromFields([
            'pubkey' => $this->keyPair->getPublicKey()->toHex(),
            'created_at' => $createdAt,
            'kind' => $kind,
            'tags' => $tags,
            'content' => 'addressed',
        ]);
        $this->assertInstanceOf(Rumour::class, $rumour);

        return $rumour->sign($this->keyPair, SignedEventFactory::signer());
    }

    private function addressIdentifierOf(Event $event): mixed
    {
        $statement = $this->pdo->prepare('SELECT address_identifier FROM events WHERE event_id = ?');
        $statement->execute([$event->getId()->toBytes()]);

        return $statement->fetchColumn();
    }

    private function rawEventOf(Event $event): mixed
    {
        $statement = $this->pdo->prepare('SELECT raw_event FROM events WHERE event_id = ?');
        $statement->execute([$event->getId()->toBytes()]);

        return $statement->fetchColumn();
    }

    private function migrationsUpTo(int $version): string
    {
        $directory = sys_get_temp_dir().'/hubstr-relay-migrations-'.bin2hex(random_bytes(6));
        mkdir($directory);

        foreach (glob(dirname(__DIR__, 4).'/resources/migrations/*.sql') ?: [] as $migration) {
            if ((int) basename($migration) <= $version) {
                copy($migration, $directory.'/'.basename($migration));
            }
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
