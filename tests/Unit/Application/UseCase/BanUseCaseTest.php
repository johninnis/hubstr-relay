<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\EventPurgerInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\BanUseCase;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Hashtag;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BanUseCaseTest extends TestCase
{
    public function testBanPubkeyRejectsActiveTenantWithoutBanningOrPurging(): void
    {
        $tenant = self::pubkey('aa');
        $state = $this->createStub(PolicyStateInterface::class);
        $state->method('isTenantPubkey')->willReturn(true);
        $management = $this->createMock(PolicyManagementInterface::class);
        $management->expects(self::never())->method('banPubkey');
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::never())->method('purgeByPubkey');

        $failure = new BanUseCase($state, $management, $purger)->banPubkey($tenant);

        $this->assertSame(TenantPolicyFailure::ActiveTenant, $failure);
    }

    public function testBanPubkeyBansAndPurgesNonTenant(): void
    {
        $pubkey = self::pubkey('bb');
        $state = $this->createStub(PolicyStateInterface::class);
        $state->method('isTenantPubkey')->willReturn(false);
        $management = $this->createMock(PolicyManagementInterface::class);
        $management->expects(self::once())->method('banPubkey')->with($pubkey);
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::once())->method('purgeByPubkey')->with($pubkey);

        $this->assertNull(new BanUseCase($state, $management, $purger)->banPubkey($pubkey));
    }

    public function testBanWordBansAndPurges(): void
    {
        $state = $this->createStub(PolicyStateInterface::class);
        $management = $this->createMock(PolicyManagementInterface::class);
        $word = BlacklistWord::fromString('spam');
        $management->expects(self::once())->method('banWord')->with($word);
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::once())->method('purgeByContentMatch')->with($word);

        new BanUseCase($state, $management, $purger)->banWord($word);
    }

    public function testBanHashtagBansAndPurges(): void
    {
        $state = $this->createStub(PolicyStateInterface::class);
        $management = $this->createMock(PolicyManagementInterface::class);
        $hashtag = Hashtag::fromString('nsfw');
        $management->expects(self::once())->method('banHashtag')->with($hashtag);
        $purger = $this->createMock(EventPurgerInterface::class);
        $purger->expects(self::once())->method('purgeByHashtag')->with($hashtag);

        new BanUseCase($state, $management, $purger)->banHashtag($hashtag);
    }

    private static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid pubkey');
    }
}
