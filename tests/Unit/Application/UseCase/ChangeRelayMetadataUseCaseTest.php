<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Application\UseCase;

use Innis\Hubstr\Relay\Application\Port\PolicyManagementInterface;
use Innis\Hubstr\Relay\Application\Port\PolicyStateInterface;
use Innis\Hubstr\Relay\Application\UseCase\ChangeRelayMetadataUseCase;
use Innis\Hubstr\Relay\Domain\Failure\MetadataFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use PHPUnit\Framework\TestCase;

final class ChangeRelayMetadataUseCaseTest extends TestCase
{
    public function testChangingTheNameKeepsTheOtherOverrides(): void
    {
        $management = $this->createMock(PolicyManagementInterface::class);
        $management->expects(self::once())->method('setMetadata')
            ->with(RelayMetadata::fromStored('Renamed', 'A relay', 'https://example.com/icon.png'));

        $failure = new ChangeRelayMetadataUseCase(self::stateWithOverrides(), $management)->changeName('  Renamed  ');

        $this->assertNull($failure);
    }

    public function testAnOverlongNameIsRefusedAndNothingIsStored(): void
    {
        $failure = new ChangeRelayMetadataUseCase(self::stateWithOverrides(), $this->managementNeverCalled())
            ->changeName(str_repeat('a', RelayMetadata::MAX_LENGTH + 1));

        $this->assertSame(MetadataFailure::NameTooLong, $failure);
    }

    public function testAnOverlongDescriptionIsRefusedAndNothingIsStored(): void
    {
        $failure = new ChangeRelayMetadataUseCase(self::stateWithOverrides(), $this->managementNeverCalled())
            ->changeDescription(str_repeat('a', RelayMetadata::MAX_LENGTH + 1));

        $this->assertSame(MetadataFailure::DescriptionTooLong, $failure);
    }

    public function testANonHttpIconIsRefusedAndNothingIsStored(): void
    {
        $failure = new ChangeRelayMetadataUseCase(self::stateWithOverrides(), $this->managementNeverCalled())
            ->changeIcon('javascript:alert(1)');

        $this->assertSame(MetadataFailure::InvalidIcon, $failure);
    }

    public function testAnEmptyIconClearsTheOverride(): void
    {
        $management = $this->createMock(PolicyManagementInterface::class);
        $management->expects(self::once())->method('setMetadata')
            ->with(RelayMetadata::fromStored('My Relay', 'A relay', null));

        $failure = new ChangeRelayMetadataUseCase(self::stateWithOverrides(), $management)->changeIcon('');

        $this->assertNull($failure);
    }

    private function managementNeverCalled(): PolicyManagementInterface
    {
        $management = $this->createMock(PolicyManagementInterface::class);
        $management->expects(self::never())->method('setMetadata');

        return $management;
    }

    private static function stateWithOverrides(): PolicyStateInterface
    {
        $state = self::createStub(PolicyStateInterface::class);
        $state->method('getMetadata')->willReturn(RelayMetadata::fromStored('My Relay', 'A relay', 'https://example.com/icon.png'));

        return $state;
    }
}
