<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use RuntimeException;

final class SignedEventFactory
{
    private static ?SignatureServiceInterface $signer = null;

    public static function signer(): SignatureServiceInterface
    {
        return self::$signer ??= Secp256k1Signer::create();
    }

    public static function signedEvent(KeyPair $keyPair, EventKind $kind, string $content, ?TagCollection $tags = null): Event
    {
        $rumour = new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            $kind,
            $tags ?? new TagCollection(),
            EventContent::fromString($content)
        );

        return $rumour->sign($keyPair, self::signer());
    }

    public static function signedEventAtTime(KeyPair $keyPair, EventKind $kind, string $content, int $timestamp, ?TagCollection $tags = null): Event
    {
        $rumour = new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::fromInt($timestamp),
            $kind,
            $tags ?? new TagCollection(),
            EventContent::fromString($content)
        );

        return $rumour->sign($keyPair, self::signer());
    }

    public static function fromRumour(Rumour $rumour): Event
    {
        return new Event($rumour, $rumour->getId(), self::fixtureSignature());
    }

    public static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid pubkey');
    }

    private static function fixtureSignature(): Signature
    {
        return Signature::tryFromHex(str_repeat('a', 128))
            ?? throw new RuntimeException('Invalid fixture signature');
    }
}
