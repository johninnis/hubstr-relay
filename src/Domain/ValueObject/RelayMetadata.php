<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Failure\MetadataFailure;

final readonly class RelayMetadata
{
    public const int MAX_LENGTH = 2048;

    private function __construct(
        private ?string $name,
        private ?string $description,
        private ?string $icon,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null);
    }

    public static function fromStored(?string $name, ?string $description, ?string $icon): self
    {
        return new self(self::presence($name), self::presence($description), self::presence($icon));
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function withName(string $name): self|MetadataFailure
    {
        $trimmed = trim($name);

        return mb_strlen($trimmed) > self::MAX_LENGTH
            ? MetadataFailure::NameTooLong
            : new self(self::presence($trimmed), $this->description, $this->icon);
    }

    public function withDescription(string $description): self|MetadataFailure
    {
        $trimmed = trim($description);

        return mb_strlen($trimmed) > self::MAX_LENGTH
            ? MetadataFailure::DescriptionTooLong
            : new self($this->name, self::presence($trimmed), $this->icon);
    }

    public function withIcon(string $icon): self|MetadataFailure
    {
        $trimmed = trim($icon);

        if ('' === $trimmed) {
            return new self($this->name, $this->description, null);
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH || !self::isAbsoluteHttpUrl($trimmed)) {
            return MetadataFailure::InvalidIcon;
        }

        return new self($this->name, $this->description, $trimmed);
    }

    private static function presence(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        return in_array($scheme, ['http', 'https'], true) && null !== $host && '' !== $host;
    }
}
