# 8. CLI tools write synchronously, bypassing the write worker

## Status

Accepted

## Context

The relay server runs on an amphp event loop. SQLite permits only one writer, so all blocking database work is pushed onto worker processes to keep the loop responsive: writes are serialised through a single write worker, driven from the main process by `WriteCoordinator` over a channel, with `WorkerEventStore` as the main-process adapter that forwards `WriteCommandInterface` commands to the worker. This machinery exists to stop a synchronous SQLite write from stalling concurrently-served WebSocket connections.

The CLI tools (`bin/import.php`, `bin/export.php`) have none of that context. They are short-lived, single-threaded batch processes with no event loop and no concurrent clients: `import.php` reads JSONL from stdin one line at a time and stores each event; `export.php` streams query results to stdout. There is nothing for a blocking write to stall — the process exists only to do that write — and nothing to serialise against, because the CLI tool is the only writer for the duration of its run.

Running the CLI tools through the worker machinery would mean spawning a worker process, establishing a channel, and paying a cross-process serialise/round-trip per command, all to protect an event loop that does not exist. It would also couple the CLI entry points to the server's worker-pool wiring for no benefit.

So the CLI tools reach the persistence stores directly. `import.php` and `export.php` each ask `RelayContainer` for a use case — `ImportEventUseCase`, `ExportEventsUseCase` — and the container wires that use case to the concrete `EventWriteStore` or `EventQueryStore` on the main-process connection, with no coordinator and no worker in between. The server path and the CLI path therefore reach SQLite through two different store front-ends — `WorkerEventStore` (asynchronous, worker-backed) on the server, the concrete stores (synchronous, in-process) on the CLI. This is a deliberate second path, not an inconsistency to consolidate. (`DirectWriteChannel`, which lets a `WriteCoordinator` apply commands synchronously without a worker, exists as a test seam for exercising the coordinator-backed code without spawning a worker; it is not part of the CLI entry points.)

## Decision

CLI batch tools write and read SQLite synchronously and in-process: their use cases are wired by `RelayContainer` to the concrete stores (`EventWriteStore`, `EventQueryStore`), bypassing the write worker and `WriteCoordinator`. The worker-backed path (`WorkerEventStore` → `WriteCoordinator` → write worker) is used only by the long-running server.

## Consequences

- CLI imports and exports are simple synchronous loops with no worker spawn, no channel, and no cross-process serialisation cost.
- There are two write front-ends by design: asynchronous and worker-backed for the concurrent server, synchronous and direct for single-threaded batch tools. The "one way to do things" rule yields here because the two contexts have genuinely different constraints (a hot event loop with many clients versus a lone batch process).
- A change to write behaviour that must hold everywhere (for example storing each event in its own transaction, ADR-0005) lives in the shared `EventWriteStore`, which both paths use — the server reaches it through the worker, the CLI directly — so the behaviour stays single-sourced even though the dispatch differs.
- Do not route the CLI tools through the worker pool to make the paths uniform: it adds a process and a round-trip to protect an event loop the CLI does not run.
