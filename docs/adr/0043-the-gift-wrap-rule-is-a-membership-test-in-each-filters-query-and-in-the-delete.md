# 43. The gift-wrap rule is a membership test in each filter's query and in the delete

## Status

Accepted

## Context

innis/nostr-relay 0.13 hands the store the rule it used to apply by rewriting filters. Every read carries the reader's acting keys, the keys the connection has authenticated as, and on every filter, before its `limit`, the store matches a kind 1059 or 21059 event only where one of its `p` tags names one of those keys (nostr-relay ADR-0036, shared nostr-adrs ADR-0122). Every deletion by id carries the deleter's acting keys, and the store deletes the events the deleter signed and the gift wraps addressed to it, never a deletion (nostr-relay ADR-0038, shared nostr-adrs ADR-0123). Both conditions are on the `p` tag, which this store already indexes in `event_tags`, keyed on `(tag_name, tag_value, event_id)` and on `(event_id, tag_name, tag_value)`.

ADR-0025 settled how a tag condition is written: as a membership test, `e.event_id IN (SELECT event_id FROM event_tags WHERE ...)`, which leaves the planner free to drive from the tag rows when that is cheap, and never as a correlated `EXISTS`, which always drives from the events side and walks the table when the condition matches little. The gift-wrap condition has a second shape to fit: for a filter that names no kind, or names a gift-wrap kind beside others, it is a disjunction ("not a gift wrap, or addressed to the reader"), and a disjunct cannot drive the query whichever way it is written.

Both shapes were measured on the relay's own database (423,610 events, six of them gift wraps, the most-mentioned key named by 10,558 `p` rows), and on a synthetic one shaped like a busy inbox (300,000 events, 200,000 of them gift wraps, 6,000 addressed to one key):

| Read, limit 500 | Membership | Correlated `EXISTS` |
|---|---|---|
| Gift-wrap kinds only, reader with no wraps, synthetic | 0.02 ms | 124 ms |
| Gift-wrap kinds only, reader with 6,000 wraps, synthetic | 16 ms | 24 ms |
| No kind, reader with wraps, synthetic | 1.96 ms | 1.15 ms |
| No kind, reader with none, synthetic | 0.53 ms | 0.94 ms |
| Kinds 1 and 1059, own database | 300 ms | 274 ms (284 ms with no condition at all) |
| COUNT of no kind, 5,000 ids, own database | 6.0 ms | 2.75 ms (0.72 ms with no condition) |

## Decision

- `EventQueryStore` adds one condition per filter, after the filter's own and before its `LIMIT`, built from the acting keys and `getConfinedKinds()`:
  - a filter naming no gift-wrap kind gets none;
  - a filter naming only gift-wrap kinds gets the membership test alone, `e.event_id IN (SELECT event_id FROM event_tags WHERE tag_name = 'p' AND tag_value IN (SELECT value FROM json_each(keys)))`, or `1 = 0` for a reader with no keys;
  - any other filter gets `(e.kind NOT IN (1059, 21059) OR <the membership test>)`, or `e.kind NOT IN (1059, 21059)` for a reader with no keys.
- `EventWriteStore::deleteByEventIds` deletes in one statement: of the ids, those whose kind is not 5 and whose `pubkey` is one of the deleter's keys, or whose kind is a gift-wrap kind and which pass the same membership test.
- The reader's keys are bound as one JSON array and read back with `json_each`, so a read's condition binds four parameters however many keys the reader holds: the tag name, the array and the two gift-wrap kinds. A reader's keys are the keys it authenticated as, and only a tenant authenticates here (ADR-0004), so their number is at most the tenants', which nothing bounds; one array keeps them out of the per-statement budget (ADR-0038). Measured on the relay's own database, the array costs what a bound list costs or less: 22.45 ms against 24.05 ms for a `#p` read of the most-mentioned key, 2.06 ms against 3.34 ms for a COUNT of no kind. The migration that backfills `address_identifier` already relies on SQLite's JSON functions, so this adds no requirement on the engine.
- The delete's gift-wrap branch binds the deleter's keys the same way, as one array. Its own-events branch, `pubkey IN (...)`, binds one parameter per key, because `pubkey` is a blob, which SQLite's JSON functions cannot carry without `unhex` (ADR-0045). A deleter acts as one key, the deletion's signer (nostr-relay ADR-0038), so the delete binds its ids and six parameters beside them: the deletion kind, the signer, the two gift-wrap kinds, the tag name and the array.
- `findRawByFilter`, the CLI read path the export tool uses, adds no condition: an operator exporting the store exports all of it, as the database file already holds it.
- `FindByFiltersQuery`, `CountByFiltersQuery` and `DeleteEventIdsCommand` carry the acting keys across the worker channel as data (ADR-0011).

## Consequences

- The store keeps the relay's read and delete contract: `WorkerEventStoreContractTest` runs every case innis/nostr-relay ships through `WorkerEventStore`, over the real query and write stores, on a channel that serialises every query, command and result as the worker boundary does.
- A filter naming only gift-wrap kinds costs what the reader's own tag rows cost, however many wraps the relay holds for other keys. Do not rewrite that test as a correlated `EXISTS`: it walks every gift wrap the relay holds when the reader has none, 124 ms at 200,000 wraps against 0.02 ms.
- In the disjunction neither shape wins on both databases, and both stay within a few milliseconds of the query without the condition. The membership test is kept there too, so the store has one shape for a tag condition, the one ADR-0025 prescribes, and its cost is bounded by the reader's own tag rows rather than by how many gift wraps a filter walks past.
- A tenant's `{}` now returns its own wraps among its other events, and a COUNT of it counts them.
- The condition binds four parameters beside a filter's values, whatever the reader holds (ADR-0038). `EventQueryStoreParameterBudgetTest` pins it with a reader holding 40,000 keys.
- Shared decisions: nostr-adrs ADR-0122, ADR-0123, ADR-0124 and ADR-0125.
