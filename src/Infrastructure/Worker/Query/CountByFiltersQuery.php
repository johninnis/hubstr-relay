<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Query;

use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Override;

final readonly class CountByFiltersQuery implements ReadQueryInterface
{
    public function __construct(
        private FilterCollection $filters,
        private int $limit,
    ) {
    }

    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        return $context->getEventQueryStore()->countByFilters($this->filters, $this->limit);
    }
}
