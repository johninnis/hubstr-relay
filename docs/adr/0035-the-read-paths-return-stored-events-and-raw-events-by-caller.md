# 35. The subscription read path returns stored events, and the CLI read path returns `RawEvent`

## Status

Accepted

Supersedes ADR-0003, which decided that the two read methods of `EventQueryStore` return different shapes, chosen by how each caller consumes them. That decision is carried forward. What is revised is the shape of the subscription read: it no longer returns raw JSON strings for the caller to parse, because the caller no longer parses them.

## Context

*Carried forward from ADR-0003.* `EventQueryStore` exposes two read methods that return events in different shapes, and a reviewer chasing uniformity will be tempted to make both return the same value object.

*What this record adds.* The subscription read used to return a list of JSON strings. It crossed a worker channel to the main process, and `WorkerEventStore` parsed every string with `Event::tryFromJson`, because nostr-relay needed an `Event` to stream. The strings were the smallest payload for a result that was going to be parsed anyway, and wrapping them would only have added bytes.

nostr-relay now streams stored events without parsing them (nostr-relay ADR-0021) and asks its receive check about an event's header rather than the event (nostr-relay ADR-0022). What it needs from the store per event is the id, author and kind, the earliest stated expiry, and the bytes. All but the expiry are columns of the `events` table, and the expiry is indexed in `event_tags` like any other tag.

## Decision

The two read methods still return different shapes on purpose, each chosen by how its caller consumes the result:

1. **`findByFilters()` returns a `StoredEventCollection`.** It is the hot subscription read path, and was `findRawJsonByFilters()`. Each `StoredEvent` is built from the row's `event_id`, `pubkey` and `kind` columns, the row's expiration tags read in one indexed lookup over the result, and `raw_event` through `EncodedEvent::fromOwnStore()` (ADR-0034). No stored event is parsed. The collection crosses the worker channel as it is, and `WorkerEventStore` returns it unchanged.
2. **`findRawByFilter()` returns `RawEvent` objects.** Its callers are CLI tools that deduplicate by id without parsing, so carrying the id alongside the raw body in a typed object is exactly what they need.

## Consequences

- A REQ answered from the store costs its query, one expiration lookup over at most the rows returned, and no parse or re-encode per event.
- The subscription result crosses the worker channel as value objects rather than bare strings. Measured over 1,000 stored 2 KB events, that adds about 1.2 KB of serialised structure per event, and serialising and rebuilding the collection costs about 2.7 µs per event against 0.2 µs for bare strings. It buys the main process the header and expiry without a parse: the whole read, from query to wire frame, fell from about 45 µs per event to about 12 µs. Flattening the rows into arrays for the channel was measured too; it saves the payload but costs more to rebuild in the main process, which is the process that serves every connection.
- The expiration lookup reads only the rows a REQ already returned, so it does not add the per-row predicate that ADR-0028 keeps out of the query builder.
- A malformed column in a returned row is a `MalformedEventRowException`, not a silently shorter result.
- The two return types are not a uniformity defect to be "fixed": matching the value to the read path it serves is the point. Do not parse `raw_event` on the subscription path to recover fields the columns already hold.
