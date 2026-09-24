<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Presentation\Cli;

use Innis\Hubstr\Relay\Application\DTO\ExportCriteria;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class ExportOptions
{
    public const array LONG_OPTIONS = ['kind:', 'author:', 'since:', 'until:', 'tagged:'];

    /**
     * @param array<array-key, mixed> $options
     */
    public static function toCriteria(array $options): ExportCriteria|ExportOptionFailure
    {
        $kind = self::option($options, 'kind', self::kind(...));
        if ($kind instanceof ExportOptionFailure) {
            return $kind;
        }

        $author = self::option($options, 'author', PublicKey::tryFromNpubOrHex(...));
        if ($author instanceof ExportOptionFailure) {
            return $author;
        }

        $since = self::option($options, 'since', Timestamp::tryFromDecimalString(...));
        if ($since instanceof ExportOptionFailure) {
            return $since;
        }

        $until = self::option($options, 'until', Timestamp::tryFromDecimalString(...));
        if ($until instanceof ExportOptionFailure) {
            return $until;
        }

        $tagged = self::option($options, 'tagged', PublicKey::tryFromNpubOrHex(...));
        if ($tagged instanceof ExportOptionFailure) {
            return $tagged;
        }

        return new ExportCriteria($kind, $author, $since, $until, $tagged);
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $options
     * @param callable(string): ?T    $parse
     *
     * @return T|ExportOptionFailure|null
     */
    private static function option(array $options, string $name, callable $parse): ?object
    {
        if (!array_key_exists($name, $options)) {
            return null;
        }

        $raw = $options[$name];

        if (!is_string($raw)) {
            return new ExportOptionFailure($name, '');
        }

        return $parse($raw) ?? new ExportOptionFailure($name, $raw);
    }

    private static function kind(string $value): ?EventKind
    {
        return ctype_digit($value) ? EventKind::tryFromInt((int) $value) : null;
    }
}
