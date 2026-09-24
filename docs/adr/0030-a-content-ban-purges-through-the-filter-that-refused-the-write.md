# 30. A content ban purges through the same filter that refuses the write, and a banned word is bounded so it can

## Status

Accepted

## Context

Banning a word does two things: it refuses matching events from then on, and it removes the matching events already stored. Those were two different questions asked two different ways.

The refusal asks `BlacklistFilter`, which lowercases the content and tests `str_contains` for each banned word. It is a substring test, so banning `spam` refuses `spammy` and `xspamx`.

The purge asked the full-text index instead. FTS5 tokenises, so a search for `spam` finds the token `spam` and nothing else. Banning `spam` therefore refused `spammy` on the way in while leaving every stored `spammy` in place, and an operator who banned a word and then queried for it still found events. Attempts to close the gap by reshaping the FTS query moved it around rather than removing it: a phrase search fixed the multi-word case and still missed punctuation, and each fix was a third definition of what the ban means.

There is only one definition worth having, and it is the one that decides whether an event is admitted. Anything else is a second rule that will drift from it again.

That choice has a price, because the filter's predicate is not indexable. A substring match with no anchor cannot use the full-text index, and `LIKE '%word%'` cannot use an ordinary index either. Answering it means reading every row's content. Measured on the two-million-event corpus:

| | |
| --- | --- |
| reading and testing every row in PHP | 5.7 s |
| the indexed full-text lookup it replaced | 0.001 s |

The purge runs on the single write worker, which accepts no event while it is busy, so that cost is paid in write latency. Chunking the delete does not reduce it: each chunk starts a fresh scan, so a purge of N events costs a scan for every chunk. At five hundred rows a chunk, removing the 357,100 events matching a common word would have cost over seven hundred full scans.

A second problem is worse than slow. Because the predicate is a bare substring test, a short enough banned word matches almost every event. `banword " "` matched every event containing a space; `banword "e"` matched three of four events in an ordinary sample. Refusing writes on such a word is recoverable, since unbanning it restores the relay. Deleting on it is not: the events are gone. Nothing stopped an operator typing it, and nothing distinguished it from a real ban.

## Decision

The purge asks `BlacklistFilter` itself. `EventWriteStore::deleteByContentMatchChunk` builds the filter from the banned word and deletes the rows it refuses, so the set a ban removes is exactly the set the same ban would have refused. There is no second matching rule anywhere.

A banned word is a `BlacklistWord`, not a string. It is trimmed and lowercased, and it must be at least three characters, which the management API enforces at the boundary by refusing a shorter one with a 400 naming the rule. Three is not a property of the protocol; it is the point below which a fragment appears in ordinary prose often enough that banning it would empty the store.

The content purge takes a chunk of fifty thousand rather than the five hundred the indexed deletes use. The scan is the cost, not the delete, so the chunk is sized to amortise it: the same 357,100-event purge becomes eight scans instead of seven hundred. Each chunked delete job carries its own chunk size and decides for itself when it has finished.

## Consequences

- An operator who bans a word and then searches for it finds nothing, because the ban and the search now agree by construction.
- A content purge is the one deletion path that costs a full table scan, and it blocks writes while it runs. It is started only by a ban, never on a timer, so the cost is bounded by how often an operator bans a word. Do not attach it to a schedule.
- Do not "optimise" the purge back onto the full-text index, or onto `LIKE`. Both are a different predicate from the one that refuses the write, and the difference is the defect this record exists to remove. If the scan has to go, the filter's predicate has to change first, for both paths at once.
- A word of one or two characters cannot be banned at all. An operator wanting to suppress a short token has to express it as something longer, or block the author.
- A stored word that no longer meets the rule is dropped when the blacklist is loaded, not kept. This is the opposite of the choice made for a stored address block (ADR-0026), and for the opposite reason: a block that fails to load protects nobody, whereas a fragment short enough to fail this rule refuses most ordinary writes, so dropping it restores the relay rather than exposing it. The API cannot unban such a word either, so loading it would leave it unmanageable.
- The chunk size is a latency trade, not a correctness one. Lowering it makes each pause shorter and the whole purge much longer; raising it does the reverse, and costs memory proportional to the row ids held.
- `EventStorageTest` covers a word matching inside a longer word and across punctuation, and `BlacklistWordTest` pins the refusals, including the single space and the single letter that used to be accepted.
