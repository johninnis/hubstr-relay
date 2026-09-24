<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Application\UseCase;

use Generator;
use Innis\Hubstr\Relay\Application\DTO\ExportCriteria;
use Innis\Hubstr\Relay\Application\Port\RawEventQueryInterface;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagFilter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;

final readonly class ExportEventsUseCase
{
    public function __construct(
        private RawEventQueryInterface $events,
    ) {
    }

    /**
     * @return Generator<int, string>
     */
    public function export(ExportCriteria $criteria): Generator
    {
        $seen = [];

        foreach ($this->buildFilters($criteria) as $filter) {
            foreach ($this->events->findRawByFilter($filter) as $rawEvent) {
                $idHex = $rawEvent->getId()->toHex();

                if (isset($seen[$idHex])) {
                    continue;
                }
                $seen[$idHex] = true;

                yield $rawEvent->getJson();
            }
        }
    }

    private function buildFilters(ExportCriteria $criteria): FilterCollection
    {
        $kinds = null !== $criteria->getKind() ? new EventKindCollection([$criteria->getKind()]) : null;
        $since = $criteria->getSince();
        $until = $criteria->getUntil();

        $filters = [];

        if (null !== $criteria->getAuthor()) {
            $filters[] = new Filter(authors: new PublicKeyCollection([$criteria->getAuthor()]), kinds: $kinds, since: $since, until: $until);
        }
        if (null !== $criteria->getTagged()) {
            $filters[] = new Filter(tags: TagFilter::fromValues([TagType::PUBKEY => [$criteria->getTagged()->toHex()]]), kinds: $kinds, since: $since, until: $until);
        }
        if ([] === $filters) {
            $filters[] = new Filter(kinds: $kinds, since: $since, until: $until);
        }

        return new FilterCollection($filters);
    }
}
