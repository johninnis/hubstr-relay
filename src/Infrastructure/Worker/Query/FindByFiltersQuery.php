<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Infrastructure\Worker\Query;

use Innis\Hubstr\Relay\Infrastructure\Worker\ReadContext;
use Innis\Hubstr\Relay\Infrastructure\Worker\ReadQueryInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Override;

final readonly class FindByFiltersQuery implements ReadQueryInterface
{
    public function __construct(
        private FilterCollection $filters,
        private ActingKeys $reader,
    ) {
    }

    #[Override]
    public function applyTo(ReadContext $context): mixed
    {
        return $context->getEventQueryStore()->findByFilters($this->filters, $this->reader);
    }
}
