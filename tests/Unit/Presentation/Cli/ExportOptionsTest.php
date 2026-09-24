<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Presentation\Cli;

use Innis\Hubstr\Relay\Application\DTO\ExportCriteria;
use Innis\Hubstr\Relay\Presentation\Cli\ExportOptionFailure;
use Innis\Hubstr\Relay\Presentation\Cli\ExportOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportOptionsTest extends TestCase
{
    private const string PUBKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testNoOptionsExportEverything(): void
    {
        $this->assertEquals(new ExportCriteria(), ExportOptions::toCriteria([]));
    }

    public function testEveryOptionIsParsedToItsDomainType(): void
    {
        $criteria = self::accepted(ExportOptions::toCriteria([
            'kind' => '1',
            'author' => self::PUBKEY,
            'since' => '100',
            'until' => '200',
            'tagged' => self::PUBKEY,
        ]));

        $this->assertSame(1, $criteria->getKind()?->toInt());
        $this->assertSame(self::PUBKEY, $criteria->getAuthor()?->toHex());
        $this->assertSame(100, $criteria->getSince()?->toInt());
        $this->assertSame(200, $criteria->getUntil()?->toInt());
        $this->assertSame(self::PUBKEY, $criteria->getTagged()?->toHex());
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('malformedOptions')]
    public function testAMalformedOptionIsRefusedByName(array $options, string $message): void
    {
        $failure = ExportOptions::toCriteria($options);

        $this->assertInstanceOf(ExportOptionFailure::class, $failure);
        $this->assertSame($message, $failure->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedOptions(): iterable
    {
        yield 'a kind that is not a number' => [['kind' => 'abc'], 'Invalid --kind: abc'];
        yield 'a negative kind' => [['kind' => '-1'], 'Invalid --kind: -1'];
        yield 'an author that is not a key' => [['author' => 'nobody'], 'Invalid --author: nobody'];
        yield 'a since that is not a timestamp' => [['since' => 'yesterday'], 'Invalid --since: yesterday'];
        yield 'an until that is not a timestamp' => [['until' => '1.5'], 'Invalid --until: 1.5'];
        yield 'a tagged key that is not a key' => [['tagged' => 'npub1nope'], 'Invalid --tagged: npub1nope'];
        yield 'a repeated option' => [['kind' => ['1', '7']], 'Invalid --kind: expected a single value'];
    }

    private static function accepted(ExportCriteria|ExportOptionFailure $outcome): ExportCriteria
    {
        self::assertInstanceOf(ExportCriteria::class, $outcome);

        return $outcome;
    }
}
