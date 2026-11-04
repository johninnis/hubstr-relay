# 46. An arriving event is checked against the stored deletion requests in its own write, through the one filter compiler

## Status

Accepted

## Context

innis/nostr-relay 0.14 requires a store to refuse, as `EventStoreOutcome::Deleted`, an event that a deletion request it holds deletes, decided in the write that would store it (nostr-relay ADR-0040, shared nostr-adrs ADR-0126). This store's writes all run on the one write worker, one command at a time (ADR-0021), and each event is stored in its own transaction (ADR-0005), so a check made inside `EventWriteStore::storeOne` cannot interleave with the request it looks for.

Finding the requests means a read inside the write. Two ways suggest themselves. The store could hold a second, hand-written SQL statement of "a kind 5 by these keys naming this id, or by this author naming this coordinate since this time". That restates in SQL what innis/nostr-core already states as filters (`DeletionCoverage::lookupFilters`), a second copy free to drift from the first. Or the write store could compile those filters with the same code `EventQueryStore` compiles every subscription filter with. That compiler was private to `EventQueryStore`, and `EventWriteStore` already takes three collaborators, so handing it the query store as a fourth would breach the parameter limit.

## Decision

- `EventWriteStore::storeOne`, after the duplicate check and before the replaceable check, fetches the stored events each of `DeletionCoverage::lookupFilters($event)` matches and refuses the event as `Deleted` when `DeletionCoverage::covers` finds that one of them deletes it. innis/nostr-core makes the decision; the store only fetches.
- The filter-to-SQL compiler is `SqliteFilterClause`, a stateless class both stores use: `EventQueryStore` for every read and count, `EventWriteStore` for this lookup. It was moved out of `EventQueryStore` unchanged.
- The lookup is prepared on the connection for its one call, not through `StatementRunner`: a gift wrap's lookup names one author per key the wrap is addressed to, so its placeholder count varies, and the runner would keep one statement per count for the life of the connection (ADR-0020).
- The store contract from innis/nostr-relay runs the shared deletion corpus against `WorkerEventStore`, both through the delete statements of ADR-0043 and through this write.

## Consequences

- An event a stored request deletes is never stored, whether it arrives live, in a batch, or through `bin/import.php`, which counts it as skipped.
- Every stored event costs one or two statements prepared and run, each an indexed read of `event_tags` by `(tag_name, tag_value)`, before its insert.
- A filter means the same thing to a subscription and to this lookup, because one compiler writes both statements.
- Do not replace the lookup with a hand-written statement, and do not move the check onto the main process before the write is dispatched: both reopen what this record closes.
- Shared decisions: nostr-adrs ADR-0124, ADR-0126 and ADR-0127.
