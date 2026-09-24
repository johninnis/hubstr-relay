<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\Collection;

use Innis\Hubstr\Relay\Domain\Collection\BlacklistWordCollection;
use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use PHPUnit\Framework\TestCase;

final class BlacklistWordCollectionTest extends TestCase
{
    public function testFromStringsKeepsOnlyValuesThatParseAsBannedWords(): void
    {
        $words = BlacklistWordCollection::fromStrings(['Spam', 'e', 42, null, '  scam  ']);

        $this->assertSame(['spam', 'scam'], $words->toStrings());
    }

    public function testContainsComparesByCanonicalWord(): void
    {
        $words = new BlacklistWordCollection([BlacklistWord::fromString('spam')]);

        $this->assertTrue($words->contains(BlacklistWord::fromString('SPAM')));
        $this->assertFalse($words->contains(BlacklistWord::fromString('scam')));
    }

    public function testDiffRemovesTheWordsOfTheOther(): void
    {
        $words = new BlacklistWordCollection([BlacklistWord::fromString('spam'), BlacklistWord::fromString('scam')]);

        $remaining = $words->diff(new BlacklistWordCollection([BlacklistWord::fromString('spam')]));

        $this->assertSame(['scam'], $remaining->toStrings());
    }
}
