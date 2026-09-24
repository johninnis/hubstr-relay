# 20. Prepared statements never leave the statement runner

## Status

Accepted

## Context

The relay stores events in SQLite in WAL mode. A single write worker owns the only writing connection and holds it for the lifetime of the process (ADR-0010), and the write path executes the same handful of statements for every event, so those statements are prepared once and reused rather than re-prepared per event.

SQLite couples statement lifetime to transaction lifetime. A statement that has stepped to a row and has not been reset holds a read lock on its connection, and it is reset only when it is executed again, finalised, or explicitly closed. A cached statement is never finalised, so a lookup that fetched one row and returned leaves that lock held indefinitely. A checkpoint cannot run on a connection that holds a read lock: it fails outright rather than copying what it can. The checkpoint SQLite attempts after each commit is therefore skipped, silently — nothing raises, writes keep succeeding, and the only visible symptom is that the WAL is appended to forever while the main database file stops changing.

The write path's lookups are precisely the ones that trip this. The duplicate check, the replaceable supersession lookup and the addressable supersession lookup each fetch a single row and return, so the first duplicate or first replacement locks the writing connection and nothing checkpoints again for the life of the process. The cached stat lookup of ADR-0009 does the same to the main-process connection. The growth is bounded only by write volume and disk, and nothing in the write path reports it.

Closing the cursor at each of those call sites fixes the instances and not the cause. It leaves a cache whose job is to hand back a raw `PDOStatement`, so the responsibility for resetting it stays with every present and future caller, invisible in the signature — and the next single-row fetch of a multi-row result reintroduces a fault whose symptom is unbounded disk growth somewhere else entirely.

## Decision

Statement reuse lives behind `StatementRunner`, and no method on it returns a `PDOStatement`. Its surface is `execute()` and `selectRow()`: each runs the statement, takes the value the caller asked for, and resets the statement before returning. A caller cannot hold an open cursor because it is never given one.

Data-access classes that reuse statements across calls — `EventWriteStore`, `Denormaliser`, `StatResultsStore` — go through the runner. Statements prepared directly on the connection for a single call are unaffected: they are freed when they fall out of scope, which resets them.

## Consequences

- No lookup can pin its connection's read snapshot, so checkpointing works and the WAL stays bounded by ordinary write volume rather than growing until the disk fills.
- `selectRow()` reads one row by design. A query returning many rows prepares its own statement and drains it (`EventQueryStore`), which resets it in the ordinary way.
- A statement whose placeholder count varies from call to call, such as a delete over a list of ids, is also prepared directly for that one call. The runner keys its cache on the SQL text, so handing it such a statement would keep one compiled statement per distinct count for the life of the connection: a content purge, whose final chunk is a different size on every ban, would grow the cache by one entry per ban. Only a statement whose text is fixed belongs in the runner.
- Do not add a method that returns a `PDOStatement` for a case the two methods do not cover — that is the shape this record exists to forbid. Add a method that materialises what the caller needs.
- The runner caching statements is not an optimisation to strip out: it is what makes the reuse it encapsulates worth having, and removing it would return callers to preparing and holding their own.
- `WalCheckpointTest` drives the real write path against a file-backed database and asserts the writing connection can still checkpoint after a duplicate and after a replacement; it fails if this design is undone.
