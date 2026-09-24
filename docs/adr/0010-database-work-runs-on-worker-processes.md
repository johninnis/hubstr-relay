# 10. Database work runs on worker processes, never the event loop

## Status

Accepted

## Context

The relay serves many concurrent WebSocket clients on a single amphp event loop. A query executed inline on that loop blocks every other connection for its whole duration, so any synchronous database call on the main process stalls the entire relay.

The store is SQLite. SQLite permits only one writer at a time: concurrent write transactions on one database serialise anyway, and competing for the write lock from several connections produces `SQLITE_BUSY` rather than parallelism. Reads, by contrast, are concurrent — many connections can read at once, especially in WAL mode.

Two shapes suggest themselves and are both wrong here. Running queries directly on the event loop keeps the code simple but blocks the loop. Giving every connection its own database handle and querying inline spreads the single write lock across many contending handles and still blocks whichever coroutine is mid-query.

## Decision

Do all database I/O on separate worker processes reached over amphp channels, so the event loop only ever sends a message and awaits a reply — it never touches the database itself.

- **One write worker.** A single `WriteWorkerTask` owns the only writing connection. The main process holds a `WriteCoordinator` that buffers events and commands and drains them to that worker over its channel, one unit at a time. Every mutation — event stores, deletions, policy and tenant changes, stat results — is serialised through this single worker, matching SQLite's one-writer reality instead of fighting it.
- **A read worker pool.** `ReadWorkerPool` owns several worker channels, each backed by a worker that keeps one connection for its lifetime. A read leases a free channel, runs, and returns it; a failed channel is replaced or retired, and the pool fails fast once exhausted. Reads thus run concurrently up to the pool size without ever blocking the loop.
- **Periodic stats off the hot path.** The scheduled explore/analytics refresh runs as its own work and returns its results as ordinary write commands applied through the `WriteCoordinator`, so a slow refresh never blocks live reads or writes.

## Consequences

- The event loop never blocks on the database; client fan-out and protocol handling stay responsive regardless of query cost.
- All writes are linearised through one worker by construction. There is no write-lock contention to tune and no second writing connection to keep coherent; the cost is that write throughput is bounded by one worker, which is why events are drained in batches and interleaved with deletes for fairness.
- Read throughput scales with the pool size, bounded by SQLite's concurrent-read behaviour, not by the loop.
- Work crosses a process boundary, so every unit of work must be serialisable and every reply must be validated on return; that requirement, and the failure-sentinel protocol that keeps the single write worker alive, are recorded in ADR-0011. The cached stat lookup taken on the main connection, recorded in ADR-0009, is the one deliberate exception to reads going through the pool.
- A worker channel closing fails the outstanding futures it owned rather than hanging callers; the main process surfaces an unexpected worker result as a fault rather than a silent default.
- The pools are owned by the relay lifecycle and ended when the kernel stops; why they are killed rather than shut down gracefully is recorded in ADR-0022.
