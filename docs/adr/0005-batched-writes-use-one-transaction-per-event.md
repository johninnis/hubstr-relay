# 5. Batched writes use one transaction per event

## Status

Accepted

## Context

SQLite permits only one writer, so every write is serialised through a single write worker process, and the `WriteCoordinator` batches up to `MAX_BATCH` queued event submissions into one `StoreEventsCommand` to amortise the channel round-trip. The worker then calls `EventWriteStore::storeBatch()` for the whole batch.

The obvious implementation wraps the whole batch in a single SQLite transaction: it is fewer `BEGIN`/`COMMIT` pairs and reads as the efficient choice. But batching here is a transport optimisation, not a unit of atomicity — the events in a batch are unrelated submissions from different clients that merely happened to be queued at the same moment. A single shared transaction couples their fates: one event that violates a constraint, or supersedes nothing and throws mid-apply, would roll back every other submission batched alongside it, and the per-event `EventStoreOutcome` (`Stored` / `Duplicate` / `Superseded`) could not be reported independently.

## Decision

Each event in a batch is stored in its own transaction (`storeOneTransactionally`). A failing event rolls back only itself and its slot in the returned array carries a `WorkerFailure`; the surrounding events still commit and return their own `EventStoreOutcome`.

## Consequences

- One malformed or constraint-violating submission cannot roll back unrelated events that were batched with it; each slot reports its own outcome or failure.
- The batch is a transport optimisation (one channel round-trip), never an atomicity boundary — there is deliberately no all-or-nothing semantics across a batch.
- This costs one transaction per event rather than one per batch. Do not "optimise" `storeBatch()` into a single enclosing transaction — it would re-couple unrelated submissions and erase per-event outcome reporting.
