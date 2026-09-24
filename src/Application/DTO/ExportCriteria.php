<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\DTO;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class ExportCriteria
{
    public function __construct(
        private ?EventKind $kind = null,
        private ?PublicKey $author = null,
        private ?Timestamp $since = null,
        private ?Timestamp $until = null,
        private ?PublicKey $tagged = null,
    ) {
    }

    public function getKind(): ?EventKind
    {
        return $this->kind;
    }

    public function getAuthor(): ?PublicKey
    {
        return $this->author;
    }

    public function getSince(): ?Timestamp
    {
        return $this->since;
    }

    public function getUntil(): ?Timestamp
    {
        return $this->until;
    }

    public function getTagged(): ?PublicKey
    {
        return $this->tagged;
    }
}
