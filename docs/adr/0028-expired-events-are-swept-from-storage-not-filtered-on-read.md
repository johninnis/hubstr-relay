# 28. Expired events are swept from storage, not filtered on read

## Status

Accepted

## Context

NIP-40 lets an event carry an `expiration` tag and asks a relay not to send such an event to clients once that time has passed. It permits, but does not require, deleting expired events.

nostr-relay implements the reading half for every host built on it (nostr-relay ADR-0014): an already-expired event is refused on arrival, and an event that expired while stored is withheld from a REQ result. Neither touches the store, and that record is explicit that what happens to the rows is the host's business. Until the host decides, an expired event keeps its row, keeps its disk, and is still counted by a NIP-45 COUNT, still aggregated into the explore and stats figures, and still written out by the export tool.

There are two ways to close that, and they are not equally good.

The first is to filter on read: the `expiration` tag is indexed in `event_tags` like every other tag, so the filter-to-SQL translation could exclude expired events with a `NOT EXISTS` subquery. It was measured on the two-million-event corpus that decided ADR-0025, because that is the corpus this relay's query shapes answer to.

| Read, two million events | Without | With |
|---|---|---|
| Search, limit 50 | 204 ms | 761 ms |
| REQ `#t nostr`, limit 50 | 133 ms | 244 ms |
| REQ `#p` mentions, limit 50 | 78 ms | 134 ms |
| REQ kind 1 and `#t bitcoin`, limit 50 | 255 ms | 328 ms |
| COUNT kind 1 by one author | 2.4 ms | 8.0 ms |
| Indexed author or kind lookups | under 1 ms | under 1 ms |

Roughly double on the tag and mention reads and close to four times on search, paid on every read for ever, and paid to hide a fraction of a percent of rows: in that corpus fewer than one event in two thousand carries an expiration tag at all. The cost lands precisely on the queries that were already the expensive ones, because the predicate is an index probe for every row the planner considers and the planner considers rows before the limit applies.

The second is to delete, which is what the specification actually contemplates. An expired event is not a row to be hidden on every future query, it is a row that should no longer exist. Removing it makes every path correct at once, with no predicate anywhere. Measured on the same corpus, finding a chunk of expired events takes 0.9 ms, because `event_tags` is keyed on `(tag_name, tag_value, event_id)` and the lookup is a range scan over expiration rows alone; deleting 602 of them, with their tags and their search-index rows, takes 182 ms once.

## Decision

Expired events are swept from storage, and reads are left alone.

`ExpirySweepScheduler` runs on the event loop every ten minutes and asks the `EventPurgerInterface` to purge expired events. That enqueues the same chunked delete job a ban already runs on, five hundred rows at a time, so the sweep is not a second deletion mechanism. Its own chunks are cheap because identifying them is an indexed range scan; the content purge that shares the mechanism is not, and ADR-0030 records what that costs. Deletion goes through `DELETE FROM events`, which the existing trigger and foreign keys follow into the search index and the derived tables.

A chunk removes an event whose `expiration` value is a non-empty run of digits with no leading zero, and whose instant is no later than the sweep's own clock. That is precisely what `Timestamp::tryFromDecimalString` accepts, and therefore exactly the set `Event::isExpiredAt()` calls expired, so the sweep and the relay's read-side check cannot disagree about what an expiry means.

An event may state more than one expiry. The tag index keeps no ordinal, so a sweep cannot tell which tag came first, and for a while this relay worked around that by deleting only once every stated expiry had passed. That was safe but it was a second definition of expiry living beside the library's, which is how the two came to disagree in the first place. The library now answers without reference to tag order: an event is expired once any expiry it states has passed (nostr-core ADR-0071). The sweep asks the same question of the index, and needs no rule of its own.

The leading-zero rule is the part that looks arbitrary and is not. `0100` is all digits, and SQLite will read it as one hundred, but the library refuses it and keeps serving the event; a sweep that read it as a number would delete an event the relay still considers live. A test drives both definitions over the same twenty values and asserts they agree on every one, including the empty string, a signed value, surrounding whitespace, exponent and decimal notation, non-Latin digits, and values above the largest integer.

Ten minutes is the window the stats rotation already takes, so nothing this relay derives trails an expiry by more than one of those windows.

## Consequences

- A COUNT, the explore and stats aggregates, and the export tool all stop reporting events their authors said should be gone, without any of them being taught what an expiry is.
- The disk an expired event occupies comes back.
- Between an expiry and the next sweep the row still exists. A subscription read already withholds it throughout that window, so the visible effect is a count that may be high by however many expired in the last ten minutes, and a count is already approximate by design (ADR-0024).
- The sweep is the only thing in the relay that deletes an event its author did not ask to delete. It is deliberately conservative about what counts as an expiry for that reason, and every case it declines to judge is a row that stays on disk rather than an event that vanishes while the relay is still serving it.
- An event stating several expiries is swept once the earliest has passed, which is the same instant the read path stops serving it. A test drives twenty-nine tag shapes, single and multiple, through the store and asserts the sweep removes an event exactly when the library calls it expired, rather than restating the rule.
- A sweep is never queued behind an unfinished one, so a slow first pass over a large backlog cannot accumulate work.
- A sweep that removes nothing is not logged. It runs every ten minutes and would otherwise announce its own idleness on that cadence, so a chunked delete reports only a completion that removed at least one event; a ban, which is started by an operator, is reported the same way.
- Do not add the `NOT EXISTS` predicate to the query builder. It is a one-line change that reads like an obvious omission, and the table above is why it is not there.
- Do not add an `expiration` column to `events` to make that predicate cheap. It buys only the ten-minute window, costs a migration over every stored event, and puts a second definition of expiry into SQL beside the one the library already applies.
