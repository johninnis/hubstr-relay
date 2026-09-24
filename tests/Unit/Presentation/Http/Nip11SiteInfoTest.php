<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Presentation\Http;

use Innis\Hubstr\Core\Domain\ValueObject\SiteInfo;
use Innis\Hubstr\Relay\Presentation\Http\Nip11SiteInfo;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip11SiteInfoTest extends TestCase
{
    public function testDerivesTheSiteIdentityFromTheNip11Document(): void
    {
        $pubkey = PublicKey::tryFromHex(str_repeat('a', 64)) ?? throw new RuntimeException('Invalid pubkey');

        $siteInfo = $this->siteInfoFrom([
            'name' => 'Test Relay',
            'version' => '0.1.0',
            'pubkey' => $pubkey->toHex(),
        ]);

        self::assertSame('Test Relay', $siteInfo->getName());
        self::assertSame('0.1.0', $siteInfo->getVersion());
        self::assertSame($pubkey->toBech32(), $siteInfo->getOwnerNpub());
    }

    public function testFallsBackToDefaultsWhenTheDocumentIsSparse(): void
    {
        $siteInfo = $this->siteInfoFrom([]);

        self::assertSame('Nostr Relay', $siteInfo->getName());
        self::assertSame('', $siteInfo->getVersion());
        self::assertNull($siteInfo->getOwnerNpub());
    }

    public function testFollowsTheDocumentAsItChangesBetweenCalls(): void
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $provider = $this->createStub(Nip11InfoProviderInterface::class);
        $provider->method('getNip11Info')->willReturn(
            Nip11Info::fromArray($relayUrl, ['name' => 'Before']),
            Nip11Info::fromArray($relayUrl, ['name' => 'After']),
        );
        $siteInfo = new Nip11SiteInfo($provider);

        $siteInfo->getSiteInfo();

        self::assertSame('After', $siteInfo->getSiteInfo()->getName());
    }

    /**
     * @param array<string, string> $document
     */
    private function siteInfoFrom(array $document): SiteInfo
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $provider = $this->createStub(Nip11InfoProviderInterface::class);
        $provider->method('getNip11Info')->willReturn(Nip11Info::fromArray($relayUrl, $document));

        return new Nip11SiteInfo($provider)->getSiteInfo();
    }
}
