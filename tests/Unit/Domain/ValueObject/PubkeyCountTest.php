<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\ValueObject\PubkeyCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PubkeyCountTest extends TestCase
{
    #[DataProvider('entriesThatAreNotAnObject')]
    public function testTryFromArrayRefusesAnEntryThatIsNotAnObject(mixed $entry): void
    {
        self::assertNull(PubkeyCount::tryFromArray($entry));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function entriesThatAreNotAnObject(): iterable
    {
        yield 'a number' => [5];
        yield 'a string' => ['five'];
        yield 'null' => [null];
    }
}
