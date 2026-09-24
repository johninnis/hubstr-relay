<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use InvalidArgumentException;
use Override;
use Stringable;

final readonly class BlacklistWord implements Stringable
{
    public const int MIN_LENGTH = 3;

    private function __construct(private string $value)
    {
    }

    // Deliberate: a banned word both refuses writes and deletes what is already stored, and the match is a substring, so a fragment short enough to appear in ordinary text would empty the store — see ADR-0030
    public static function tryFromString(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        $canonical = mb_strtolower(trim($value));

        return mb_strlen($canonical) >= self::MIN_LENGTH ? new self($canonical) : null;
    }

    public static function fromString(string $value): self
    {
        return self::tryFromString($value)
            ?? throw new InvalidArgumentException('A banned word must be at least '.self::MIN_LENGTH.' characters once trimmed');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
