<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\RemoveTenantUseCase;
use Innis\Hubstr\Relay\Domain\Failure\TenantPolicyFailure;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoveTenantUseCaseTest extends TestCase
{
    public function testARemovedTenantIsNotAFailure(): void
    {
        $failure = new RemoveTenantUseCase(self::state(stillTenant: false), self::management(removed: true))->remove(self::pubkey('aa'));

        $this->assertNull($failure);
    }

    public function testATenantTheStoreKeptIsTheLastTenant(): void
    {
        $failure = new RemoveTenantUseCase(self::state(stillTenant: true), self::management(removed: false))->remove(self::pubkey('aa'));

        $this->assertSame(TenantPolicyFailure::LastTenant, $failure);
    }

    public function testRemovingANonTenantIsANoOp(): void
    {
        $failure = new RemoveTenantUseCase(self::state(stillTenant: false), self::management(removed: false))->remove(self::pubkey('bb'));

        $this->assertNull($failure);
    }

    private static function state(bool $stillTenant): PolicyStateInterface
    {
        $state = self::createStub(PolicyStateInterface::class);
        $state->method('isTenantPubkey')->willReturn($stillTenant);

        return $state;
    }

    private static function management(bool $removed): PolicyManagementInterface
    {
        $management = self::createStub(PolicyManagementInterface::class);
        $management->method('removeTenantUnlessLast')->willReturn($removed);

        return $management;
    }

    private static function pubkey(string $byte): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat($byte, 32))
            ?? throw new RuntimeException('Invalid pubkey');
    }
}
