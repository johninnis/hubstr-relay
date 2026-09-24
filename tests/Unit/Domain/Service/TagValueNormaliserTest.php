<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\Service;

use Innis\Hubstr\Relay\Domain\Service\TagValueNormaliser;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\TestCase;

final class TagValueNormaliserTest extends TestCase
{
    public function testLowercasesHashtagValues(): void
    {
        self::assertSame('nostr', TagValueNormaliser::normalise(TagType::hashtag(), 'NoStR'));
    }

    public function testKeepsAnEmptyHashtagValueRatherThanRefusingIt(): void
    {
        self::assertSame('', TagValueNormaliser::normalise(TagType::hashtag(), ''));
    }

    public function testLeavesOtherTagValuesUntouched(): void
    {
        self::assertSame('AbC', TagValueNormaliser::normalise(TagType::event(), 'AbC'));
        self::assertSame('Wss://Relay.Example', TagValueNormaliser::normalise(TagType::fromString(TagType::REFERENCE), 'Wss://Relay.Example'));
    }
}
