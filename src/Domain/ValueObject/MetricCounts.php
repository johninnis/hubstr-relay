<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Enum\StatMetric;

final readonly class MetricCounts
{
    /**
     * @param array<string, int> $counts
     */
    private function __construct(
        private array $counts,
    ) {
    }

    /**
     * @param callable(StatMetric): int $count
     */
    public static function forEachMetric(callable $count): self
    {
        $counts = [];

        foreach (StatMetric::cases() as $metric) {
            $counts[$metric->value] = $count($metric);
        }

        return new self($counts);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $counts = [];

        foreach (StatMetric::cases() as $metric) {
            $count = $data[$metric->value] ?? 0;

            if (!is_int($count)) {
                return null;
            }

            $counts[$metric->value] = $count;
        }

        return new self($counts);
    }

    public function get(StatMetric $metric): int
    {
        return $this->counts[$metric->value];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }
}
