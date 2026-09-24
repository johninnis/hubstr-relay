<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Infrastructure\Persistence;

use Innis\Hubstr\Core\Infrastructure\Persistence\SchemaMigrator;
use Innis\Hubstr\Core\Infrastructure\Persistence\SqliteDatabase;
use Innis\Hubstr\Relay\Infrastructure\Persistence\EventWriteStore;
use Innis\Hubstr\Relay\Infrastructure\Worker\WriteContext;
use Innis\Hubstr\Relay\Tests\Support\SignedEventFactory;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DenormaliserTest extends TestCase
{
    private PDO $pdo;
    private EventWriteStore $store;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::inMemory()->connect();
        new SchemaMigrator($this->pdo)->migrate(dirname(__DIR__, 4).'/resources/migrations');

        $this->keyPair = KeyPair::generate(SignedEventFactory::signer());
        $this->store = WriteContext::forConnection($this->pdo)->getEventWriteStore();
    }

    public function testFollowListPopulatesProfileFollows(): void
    {
        $followed1 = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();
        $followed2 = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();

        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection([
            Tag::pubkey($followed1),
            Tag::pubkey($followed2),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_follows WHERE follower_pubkey = ?');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testLargeFollowListInsertsEveryRowAcrossChunks(): void
    {
        $tags = [];
        for ($i = 0; $i < 250; ++$i) {
            $tags[] = Tag::pubkey(PublicKey::tryFromHex(bin2hex(random_bytes(32))) ?? throw new RuntimeException('Invalid pubkey'));
        }

        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', new TagCollection($tags));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_follows WHERE follower_pubkey = ?');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        $this->assertSame(250, (int) $stmt->fetchColumn());
    }

    public function testNewerFollowListReplacesOlder(): void
    {
        $followed1 = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();
        $followed2 = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();

        $older = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', time() - 100, new TagCollection([
            Tag::pubkey($followed1),
            Tag::pubkey($followed2),
        ]));
        $newer = SignedEventFactory::signedEventAtTime($this->keyPair, EventKind::fromInt(EventKind::FOLLOW_LIST), '', time(), new TagCollection([
            Tag::pubkey($followed1),
        ]));

        $this->store->store($older);
        $this->store->store($newer);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_follows WHERE follower_pubkey = ?');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testMuteListPopulatesProfileMutes(): void
    {
        $muted = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();

        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::MUTE_LIST), '', new TagCollection([
            Tag::pubkey($muted),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_mutes WHERE muter_pubkey = ?');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRelayListPopulatesProfileRelays(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::RELAY_LIST), '', new TagCollection([
            Tag::tryFromArray(['r', 'wss://relay1.example.com', 'read']),
            Tag::tryFromArray(['r', 'wss://relay2.example.com', 'write']),
            Tag::tryFromArray(['r', 'wss://relay3.example.com']),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT relay_url, marker FROM profile_relays WHERE pubkey = ? ORDER BY relay_url');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows);
        $this->assertSame('read', $rows[0]['marker']);
        $this->assertSame('write', $rows[1]['marker']);
        $this->assertSame('both', $rows[2]['marker']);
    }

    public function testRelayListStoresTheCanonicalRelayUrl(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::RELAY_LIST), '', new TagCollection([
            Tag::tryFromArray(['r', 'wss://Relay.Example.com:443/']),
        ]));
        $this->store->store($event);

        $this->assertSame(['wss://relay.example.com'], $this->profileRelayUrls());
    }

    public function testRelayListSkipsATagWhoseUrlIsNotARelayUrl(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::RELAY_LIST), '', new TagCollection([
            Tag::tryFromArray(['r', 'https://not-a-relay.example.com']),
            Tag::tryFromArray(['r', 'wss://relay.example.com']),
        ]));
        $this->store->store($event);

        $this->assertSame(['wss://relay.example.com'], $this->profileRelayUrls());
    }

    public function testRelayListTreatsAnUnknownMarkerAsBoth(): void
    {
        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::RELAY_LIST), '', new TagCollection([
            Tag::tryFromArray(['r', 'wss://relay.example.com', 'sometimes']),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT marker FROM profile_relays WHERE pubkey = ?');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        $this->assertSame(['both'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function profileRelayUrls(): array
    {
        $stmt = $this->pdo->prepare('SELECT relay_url FROM profile_relays WHERE pubkey = ? ORDER BY relay_url');
        $stmt->execute([hex2bin($this->keyPair->getPublicKey()->toHex())]);

        return array_values(array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testZapReceiptPopulatesZapReceipts(): void
    {
        $senderPk = KeyPair::generate(SignedEventFactory::signer());
        $recipient = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();

        $zapRequestJson = json_encode([
            'pubkey' => $senderPk->getPublicKey()->toHex(),
            'content' => 'Great post!',
            'tags' => [['amount', '21000']],
        ], JSON_THROW_ON_ERROR);

        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::ZAP_RECEIPT), '', new TagCollection([
            Tag::pubkey($recipient),
            Tag::tryFromArray(['P', $senderPk->getPublicKey()->toHex()]),
            Tag::tryFromArray(['bolt11', 'lnbc210n1pexample']),
            Tag::tryFromArray(['description', $zapRequestJson]),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT * FROM zap_receipts WHERE event_id = ?');
        $stmt->execute([hex2bin($event->getId()->toHex())]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        $recipientPubkey = $row['recipient_pubkey'] ?? null;
        $senderPubkey = $row['sender_pubkey'] ?? null;
        $this->assertIsString($recipientPubkey);
        $this->assertIsString($senderPubkey);

        $this->assertSame($recipient->toHex(), bin2hex($recipientPubkey));
        $this->assertSame($senderPk->getPublicKey()->toHex(), bin2hex($senderPubkey));
        $this->assertEquals(21000, $row['amount_msats']);
    }

    public function testForgedZapReceiptWithoutBolt11IsIgnored(): void
    {
        $senderPk = KeyPair::generate(SignedEventFactory::signer());
        $recipient = KeyPair::generate(SignedEventFactory::signer())->getPublicKey();

        $zapRequestJson = json_encode([
            'pubkey' => $senderPk->getPublicKey()->toHex(),
            'content' => '',
            'tags' => [['amount', '9223372036854775807']],
        ], JSON_THROW_ON_ERROR);

        $event = SignedEventFactory::signedEvent($this->keyPair, EventKind::fromInt(EventKind::ZAP_RECEIPT), '', new TagCollection([
            Tag::pubkey($recipient),
            Tag::tryFromArray(['P', $senderPk->getPublicKey()->toHex()]),
            Tag::tryFromArray(['description', $zapRequestJson]),
        ]));
        $this->store->store($event);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM zap_receipts WHERE event_id = ?');
        $stmt->execute([hex2bin($event->getId()->toHex())]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
