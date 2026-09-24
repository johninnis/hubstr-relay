<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlacklistWordTest extends TestCase
{
    #[DataProvider('wordsThatWouldMatchOrdinaryText')]
    public function testAWordShortEnoughToMatchOrdinaryTextIsRefused(mixed $value): void
    {
        $this->assertNull(BlacklistWord::tryFromString($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function wordsThatWouldMatchOrdinaryText(): iterable
    {
        yield 'a single space matches every sentence' => [' '];
        yield 'only whitespace' => ['   '];
        yield 'empty' => [''];
        yield 'one letter appears in nearly every event' => ['e'];
        yield 'two letters' => ['th'];
        yield 'two letters once trimmed' => ['  th  '];
        yield 'not a string' => [42];
        yield 'null' => [null];
    }

    public function testAWordIsLowercasedAndTrimmed(): void
    {
        $this->assertSame('spam', (string) BlacklistWord::fromString('  SpAm  '));
    }

    public function testAWordKeepsItsInternalSpacing(): void
    {
        $this->assertSame('buy now', (string) BlacklistWord::fromString('Buy Now'));
    }

    public function testTheShortestAcceptableWordIsKept(): void
    {
        $this->assertSame('xyz', (string) BlacklistWord::fromString('xyz'));
    }

    public function testFromStringThrowsOnAWordItWouldRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlacklistWord::fromString('e');
    }

    public function testTwoWordsDifferingOnlyInCaseAreEqual(): void
    {
        $this->assertTrue(BlacklistWord::fromString('Spam')->equals(BlacklistWord::fromString('spam')));
    }
}
