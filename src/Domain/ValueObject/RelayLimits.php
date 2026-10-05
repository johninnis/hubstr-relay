<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\EventLimits;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionLimits;
use InvalidArgumentException;

final readonly class RelayLimits
{
    public function __construct(
        private int $maxSubscriptions,
        private int $maxFilters,
        private int $maxLimit,
        private int $maxContentLength,
        private int $maxFilterValues,
    ) {
        $limits = [
            'max_subscriptions' => $maxSubscriptions,
            'max_filters' => $maxFilters,
            'max_limit' => $maxLimit,
            'max_content_length' => $maxContentLength,
            'max_filter_values' => $maxFilterValues,
        ];

        foreach ($limits as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('limits.%s must be a positive integer, got %d', $name, $value));
            }
        }
    }

    public static function defaults(): self
    {
        return new self(
            maxSubscriptions: 20,
            maxFilters: 5,
            maxLimit: 1000,
            maxContentLength: 65536,
            maxFilterValues: 5000,
        );
    }

    public function getMaxSubscriptions(): int
    {
        return $this->maxSubscriptions;
    }

    public function getMaxFilters(): int
    {
        return $this->maxFilters;
    }

    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }

    public function getMaxContentLength(): int
    {
        return $this->maxContentLength;
    }

    public function getMaxFilterValues(): int
    {
        return $this->maxFilterValues;
    }

    public function toSubscriptionLimits(): SubscriptionLimits
    {
        return new SubscriptionLimits($this->maxSubscriptions, $this->maxFilters, $this->maxLimit, $this->maxFilterValues);
    }

    public function toEventLimits(): EventLimits
    {
        return new EventLimits(maxContentLength: $this->maxContentLength);
    }
}
