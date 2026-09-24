<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

final readonly class FtsQuery
{
    public static function allTokens(string $input): ?string
    {
        $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ([] === $tokens) {
            return null;
        }

        return implode(' ', array_map(self::quote(...), $tokens));
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
