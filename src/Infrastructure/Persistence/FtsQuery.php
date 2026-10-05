<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Persistence;

final readonly class FtsQuery
{
    private const string EXTENSION_PATTERN = '/^[a-z][a-z0-9_]*:[^:\s]+$/i';

    // Deliberate: only a word-colon-value token is a NIP-50 extension and skipped — a value holding a second colon, as a URL does, stays a search term. Do not widen the pattern — see ADR-0041
    public static function allTokens(string $input): ?string
    {
        $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => 1 !== preg_match(self::EXTENSION_PATTERN, $token)));

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
