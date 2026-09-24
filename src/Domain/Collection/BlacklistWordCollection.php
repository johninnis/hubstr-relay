<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\Collection;

use Innis\Hubstr\Relay\Domain\ValueObject\BlacklistWord;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<BlacklistWord>
 */
final class BlacklistWordCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return BlacklistWord::class;
    }

    private static function keyOf(BlacklistWord $word): string
    {
        return (string) $word;
    }

    public static function fromStrings(mixed $values): self
    {
        return self::fromEach($values, BlacklistWord::tryFromString(...));
    }

    public function contains(BlacklistWord $word): bool
    {
        return $this->containsByKey(self::keyOf($word), self::keyOf(...));
    }

    public function diff(self $other): self
    {
        return new self($this->retainByKey($other, self::keyOf(...), false));
    }

    /**
     * @return list<string>
     */
    public function toStrings(): array
    {
        return $this->mapItems(self::keyOf(...));
    }
}
