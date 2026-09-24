<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use InvalidArgumentException;

final readonly class RelayLimits
{
    public function __construct(
        private int $maxSubscriptions,
        private int $maxFilters,
        private int $maxLimit,
        private int $maxContentLength,
    ) {
        foreach (['max_subscriptions' => $maxSubscriptions, 'max_filters' => $maxFilters, 'max_content_length' => $maxContentLength] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('limits.%s must be a positive integer, got %d', $name, $value));
            }
        }

        if (!SubscriptionLimits::isQueryLimitInRange($maxLimit)) {
            throw new InvalidArgumentException(sprintf('limits.max_limit must be between %d and %d, got %d', SubscriptionLimits::MIN_QUERY_LIMIT, Filter::MAX_LIMIT, $maxLimit));
        }
    }

    public static function defaults(): self
    {
        return new self(
            maxSubscriptions: 20,
            maxFilters: 5,
            maxLimit: 1000,
            maxContentLength: 65536,
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

    public function toSubscriptionLimits(): SubscriptionLimits
    {
        return new SubscriptionLimits($this->maxSubscriptions, $this->maxFilters, $this->maxLimit);
    }
}
