<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

interface ExploreEntryInterface
{
    public function getCount(): int;

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array;
}
