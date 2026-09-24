<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Failure\MetadataFailure;
use Innis\Hubstr\Relay\Domain\ValueObject\RelayMetadata;
use PHPUnit\Framework\TestCase;

final class RelayMetadataTest extends TestCase
{
    public function testEmptyHasNoOverrides(): void
    {
        $metadata = RelayMetadata::empty();

        $this->assertNull($metadata->getName());
        $this->assertNull($metadata->getDescription());
        $this->assertNull($metadata->getIcon());
    }

    public function testWithNameTrimsAndStores(): void
    {
        $metadata = self::accepted(RelayMetadata::empty()->withName('  Hubstr Relay  '));

        $this->assertSame('Hubstr Relay', $metadata->getName());
    }

    public function testWithNameRejectsExcessiveLength(): void
    {
        $this->assertSame(MetadataFailure::NameTooLong, RelayMetadata::empty()->withName(str_repeat('a', RelayMetadata::MAX_LENGTH + 1)));
    }

    public function testTheLengthCapCountsCharactersNotBytes(): void
    {
        $accented = str_repeat('é', RelayMetadata::MAX_LENGTH);

        $this->assertSame($accented, self::accepted(RelayMetadata::empty()->withName($accented))->getName());
        $this->assertSame($accented, self::accepted(RelayMetadata::empty()->withDescription($accented))->getDescription());
    }

    public function testWithDescriptionRejectsExcessiveLength(): void
    {
        $this->assertSame(MetadataFailure::DescriptionTooLong, RelayMetadata::empty()->withDescription(str_repeat('a', RelayMetadata::MAX_LENGTH + 1)));
    }

    public function testWithIconAcceptsHttpAndHttps(): void
    {
        $this->assertSame('https://example.com/icon.png', self::accepted(RelayMetadata::empty()->withIcon('https://example.com/icon.png'))->getIcon());
        $this->assertSame('http://localhost:8080/icon.png', self::accepted(RelayMetadata::empty()->withIcon('http://localhost:8080/icon.png'))->getIcon());
    }

    public function testWithIconRejectsNonHttpSchemesAndRelativeUrls(): void
    {
        $this->assertSame(MetadataFailure::InvalidIcon, RelayMetadata::empty()->withIcon('data:image/png;base64,iVBORw0KGgo='));
        $this->assertSame(MetadataFailure::InvalidIcon, RelayMetadata::empty()->withIcon('javascript:alert(1)'));
        $this->assertSame(MetadataFailure::InvalidIcon, RelayMetadata::empty()->withIcon('/icon.png'));
    }

    public function testWithIconRejectsExcessiveLength(): void
    {
        $this->assertSame(MetadataFailure::InvalidIcon, RelayMetadata::empty()->withIcon('https://example.com/'.str_repeat('a', RelayMetadata::MAX_LENGTH)));
    }

    public function testWithIconEmptyStringClears(): void
    {
        $metadata = self::accepted(RelayMetadata::fromStored(null, null, 'https://example.com/icon.png')->withIcon(''));

        $this->assertNull($metadata->getIcon());
    }

    public function testWithNameEmptyStringClears(): void
    {
        $metadata = self::accepted(RelayMetadata::fromStored('My Relay', null, null)->withName('  '));

        $this->assertNull($metadata->getName());
    }

    public function testWithDescriptionEmptyStringClears(): void
    {
        $metadata = self::accepted(RelayMetadata::fromStored(null, 'A relay', null)->withDescription(''));

        $this->assertNull($metadata->getDescription());
    }

    public function testFromStoredNormalisesEmptyStringsToNull(): void
    {
        $metadata = RelayMetadata::fromStored('', '', '');

        $this->assertNull($metadata->getName());
        $this->assertNull($metadata->getDescription());
        $this->assertNull($metadata->getIcon());
    }

    public function testTransformationsAreIndependentAndImmutable(): void
    {
        $original = RelayMetadata::fromStored('Name', 'Desc', 'https://example.com/icon.png');
        $renamed = self::accepted($original->withName('Renamed'));

        $this->assertSame('Name', $original->getName());
        $this->assertSame('Renamed', $renamed->getName());
        $this->assertSame('Desc', $renamed->getDescription());
        $this->assertSame('https://example.com/icon.png', $renamed->getIcon());
    }

    private static function accepted(RelayMetadata|MetadataFailure $outcome): RelayMetadata
    {
        self::assertInstanceOf(RelayMetadata::class, $outcome);

        return $outcome;
    }
}
