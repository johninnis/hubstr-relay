# 45. A statement guest scoping grows past SQLite's parameter limit is read in parts

## Status

Accepted

## Context

`EventQueryStore` turns each filter into one statement that binds one parameter per value, and SQLite refuses a statement binding more than 32766 by default (ADR-0038). The client's own values are bounded before they reach the store: `max_filter_values` is at most `EventQueryStore::MAX_FILTER_VALUES`, 30000, and the gift-wrap condition binds four parameters whatever the reader holds (ADR-0043).

Guest scoping is not bounded the same way. For a guest, `HubstrPolicy` scopes a filter that names no authors to every tenant, and one that names no kinds to every readable kind, after the client's values were counted. Neither list has a limit: tenants are added over the management API, and the readable kinds are whatever the guest policy lists, up to the 65,536 kinds there are. A filter carrying 30,000 tag values and no authors, read by a guest on a relay with a few thousand tenants, would bind past the limit and fail as a database error in a read worker, which is what ADR-0038 exists to prevent. Refusing it would answer a client for something the operator configured; capping the tenants or the readable kinds would make an operator's configuration depend on the engine's build.

The values a filter matches are a union over its list of authors and its list of kinds, so the filter can be read as several filters, each holding part of one list, without changing what it matches.

## Decision

- Before it reads a filter, `EventQueryStore` counts the parameters its statement would bind. A filter under SQLite's default of 32766 is read as one statement, as before.
- A filter over it is split on whichever of its authors and kinds lists is longer, into two halves, and each half is split again until every part's statement fits. The parts' events are merged newest first, the lower id first at a tie, and cut to the filter's own `limit`; a COUNT counts the union of the parts' ids, at most the ceiling from each part, as it counts the union of several filters (ADR-0024).
- Only those two lists are split. The client's own values are bounded by `MAX_FILTER_VALUES`, so a statement over the limit is always one that scoping grew.

## Consequences

- No read fails at the engine for its size, whatever the tenant set or the guest policy holds. `EventQueryStoreParameterBudgetTest` pins a filter of 40,000 authors and one of all 65,536 kinds, each read in statements of at most 32766 parameters with the same events, the same order and the same count a single statement would give, and that a reader receives and counts the gift wraps addressed to it and no others across the parts, as it would from one statement (ADR-0043).
- An ordinary filter is read exactly as before: the split happens only past the limit, so no query plan measured in ADR-0025, ADR-0028 or ADR-0043 changes.
- A split filter costs one statement per part. That is the price of a configuration far larger than this relay is built for, and it is paid only by the reads that configuration produces.
- The limit is the upstream default. A build that allows more, as some distributions' do, still reads such a filter in parts; that is cheaper than depending on how the engine was compiled.
- Do not replace the split by refusing the read, which answers a client for the operator's configuration, or by capping the tenants or the readable kinds, which makes the operator's configuration depend on how the engine was built. Do not bind every list as a JSON array to avoid it: authors and ids are blobs that SQLite's JSON functions cannot carry without `unhex`, which arrived in SQLite 3.41, later than some distributions still ship, and the bound lists are the shapes ADR-0025 measured.
