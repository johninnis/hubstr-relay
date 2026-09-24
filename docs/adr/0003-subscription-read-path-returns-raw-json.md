# 3. The subscription read path returns raw JSON, not `RawEvent`

## Status

Accepted

## Context

`EventQueryStore` exposes two read methods that return events in different shapes. A reviewer chasing uniformity would be tempted to make both return the same `RawEvent` value object.

## Decision

The two methods return different shapes on purpose, each chosen by how its caller consumes the result:

1. **`findRawJsonByFilters()` returns raw JSON strings.** This is the hot subscription read path. Its result crosses a worker channel to the main process, and the caller recomputes the event id via `Event::tryFromJson` regardless — so wrapping each row in a `RawEvent` value object would only add wire payload to every delivered event for no gain.
2. **`findRawByFilter()` returns `RawEvent` objects.** Its callers are CLI tools that deduplicate by id *without* parsing, so carrying the id alongside the raw body in a typed object is exactly what they need.

## Consequences

- The hot path carries the minimum payload across the worker boundary; the CLI path carries the id it needs without re-parsing.
- The two return types are not a uniformity defect to be "fixed" — matching the value-object payload to the read path it serves is the point.
