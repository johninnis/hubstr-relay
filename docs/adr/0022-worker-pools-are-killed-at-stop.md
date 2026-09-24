# 22. Worker pools are killed at stop, never shut down gracefully

## Status

Accepted

## Context

The relay runs its database work on worker processes (ADR-0010): one write worker, a pool of read workers, and a pool for the scheduled stats refresh. Each worker runs a task that loops on its channel until the channel closes, so a worker never finishes on its own.

amphp offers two ways to end a pool. `shutdown()` waits for every worker's current task to complete before exiting the worker; `kill()` ends the worker processes at once. Called on a pool whose tasks are the relay's channel loops, `shutdown()` waits for a completion that never comes, and the process hangs on stop until the supervisor kills it. `kill()` reads as the harsher choice and is the only one that returns.

Nothing is lost by it. The kernel stops the HTTP server before the lifecycle, and a stopped server has finished every in-flight request, so no client is still waiting on a worker when the pools end. Each event is stored in its own transaction (ADR-0005) and SQLite's journal rolls back whatever a killed worker had begun, so the database is consistent after a kill. A stats refresh that is cut short is recomputed at the next refresh (ADR-0012).

A pool that nothing references is ended the same way: amphp kills the workers of a pool when it is garbage-collected. The relay must therefore keep every pool referenced for the lifetime of the process, or a worker dies while the channel to it is still in use.

## Decision

`RelayLifecycle` owns the write and read worker pools alongside a collection of schedulers. Its `stop()` stops every scheduler and then kills both pools. It is the lifecycle the kernel runs, so the pools end when the kernel stops, deterministically and before the process exits. A scheduler owning a pool of its own kills it in its own `stop()`, which is how the stats pool ends; a scheduler owning none, such as the retention sweep, has nothing to kill.

## Consequences

- Shutdown returns promptly: the pools end with a kill, not a wait for tasks that only end when their channels close.
- The pools have one owner with a defined end. Do not hold a pool alive by capturing it in a closure or a channel wrapper; ownership belongs in the lifecycle.
- Do not replace `kill()` with `shutdown()` to make the stop "graceful". The worker loops never complete, so the stop would never return. A graceful stop would need the channels closed first, and there is nothing in flight to be graceful about once the server has stopped.
