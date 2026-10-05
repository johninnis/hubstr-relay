# 38. A filter holding more values than SQLite binds is refused before it reaches the store

## Status

Accepted

## Context

`EventQueryStore` turns each filter into one SQL statement and binds one parameter per value: every id, author and kind, and every tag value with its tag name. SQLite refuses a statement with more parameters than its limit, 32766 by default, and the read then fails with a database error inside a read worker.

innis/nostr-core used to refuse a filter with more than 1000 values in a field, which kept every query far below that limit by accident. It no longer does (its ADR-0131): how many values a relay serves is the relay's policy, and innis/nostr-relay now answers it with a configurable `max_filter_values`, refused with `blocked: too many values in one filter (max N)` for every client, tenant included (its ADR-0024). Without a figure here, a REQ or COUNT naming forty thousand authors would reach the store and fail there instead of being refused.

## Decision

- `limits.max_filter_values` configures the ceiling, defaulting to 5000. `HubstrPolicy::allowSubscription()` applies it through `SubscriptionLimits::refuseOversizedFilters()` before the tenant check, so it binds a tenant as well as a guest, as the read ceiling does (ADR-0004).
- `EventQueryStore::MAX_FILTER_VALUES` (30000) is the most the store accepts, below SQLite's 32766 with room for the parameters a filter binds besides its values: up to 52 tag names, `since`, `until`, `limit` and `search`, and the tenant authors and readable kinds guest scoping adds. `RelayConfigLoader` refuses a `max_filter_values` above it at startup; the check lives in the loader rather than the config value because the figure is a fact of the store, an infrastructure detail a parsed config value may not reach into.
- `max_limit` is any positive integer: the library no longer caps a filter's `limit` (innis/nostr-core ADR-0102), so this relay's figure is the only one, published as NIP-11 `max_limit`. NIP-11 has no field for a value count, so `max_filter_values` is answered only by the refusal.

## Consequences

- A REQ or COUNT over the ceiling is answered with a `CLOSED` naming the figure and never reaches a read worker. `GuestAccessControlTest` pins it for a tenant with a filter of forty thousand authors.
- A host that lowers SQLite's parameter limit, or adds tenants by the thousand, lowers `max_filter_values` with it.
- Do not move the check below the tenant bypass, and do not raise `MAX_FILTER_VALUES` toward SQLite's limit without counting what else one statement binds.
