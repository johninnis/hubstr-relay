<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Enum\ExplorePeriod;
use Innis\Hubstr\Relay\Domain\Enum\StatName;

final readonly class StatKey
{
    private const string TOTALS_NAME = 'totals';

    private function __construct(
        private string $name,
        private ExplorePeriod $period,
    ) {
    }

    public static function forStat(StatName $stat, ExplorePeriod $period): self
    {
        return new self($stat->value, $period);
    }

    public static function totals(): self
    {
        return new self(self::TOTALS_NAME, ExplorePeriod::All);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPeriod(): ExplorePeriod
    {
        return $this->period;
    }
}
