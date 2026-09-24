<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Domain\ValueObject;

use Innis\Hubstr\Relay\Domain\Collection\KindCountCollection;
use Innis\Hubstr\Relay\Domain\Collection\PubkeyCountCollection;

final readonly class StatTotals
{
    public function __construct(
        private MetricCounts $counts,
        private KindCountCollection $eventsByKind,
        private PubkeyCountCollection $eventsByTenant,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $counts = MetricCounts::tryFromArray($data);
        $eventsByKind = KindCountCollection::tryFromArray($data['events_by_kind'] ?? []);
        $eventsByTenant = PubkeyCountCollection::tryFromArray($data['events_by_tenant'] ?? []);

        return null === $counts || null === $eventsByKind || null === $eventsByTenant
            ? null
            : new self($counts, $eventsByKind, $eventsByTenant);
    }

    public function getCounts(): MetricCounts
    {
        return $this->counts;
    }

    public function getEventsByKind(): KindCountCollection
    {
        return $this->eventsByKind;
    }

    public function getEventsByTenant(): PubkeyCountCollection
    {
        return $this->eventsByTenant;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->counts->toArray(),
            'events_by_kind' => $this->eventsByKind->toWireArray(),
            'events_by_tenant' => $this->eventsByTenant->toWireArray(),
        ];
    }
}
