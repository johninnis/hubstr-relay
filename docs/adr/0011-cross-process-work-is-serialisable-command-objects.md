# 11. Cross-process reads and writes are serialisable command objects

## Status

Accepted

## Context

Database work runs on worker processes reached over amphp channels (ADR-0010). The main process and a worker therefore do not share memory: anything sent between them is serialised, sent as bytes, and rebuilt on the far side. A closure, a bound handler, or an object holding a live connection or socket cannot cross that boundary.

This rules out the obvious in-process shapes. The main process cannot hand the worker a callback to run, nor a repository instance to call — neither survives serialisation. It needs to express "store these events", "delete these ids for this author", "save this setting", "persist this stat result" as plain data the worker can reconstruct and act on against its own connection.

A single generic message carrying a type tag plus a payload, dispatched by a `match` on the tag inside the worker, is the tempting consolidation: it collapses the many message types into one. But it moves the per-operation logic into one growing switch in the worker, recreating the coupling the worker boundary exists to avoid and forcing every new operation to edit a shared dispatcher.

## Decision

Model each cross-process unit of work as its own small, serialisable value object implementing a `WriteCommandInterface` or `ReadQueryInterface`, whose single `applyTo(WriteContext|ReadContext): mixed` method carries the operation's logic. The worker's loop is then fully generic — it receives a command, calls `$command->applyTo($context)`, and sends back the result — with no type switch anywhere.

- A command/query holds only serialisable data (ids, pubkeys, words, chunk sizes), never closures or live resources. The `WriteContext` / `ReadContext` it is applied to is constructed inside the worker and owns that worker's stores.
- The proliferation of small command classes (`StoreEventsCommand`, `DeleteEventIdsCommand`, `SaveSettingCommand`, `PersistStatResultCommand`, and so on) is the **required form of this boundary, not boilerplate to be merged**. Each class is the serialisable representation of one operation; there is nothing to extract because there is no shared runtime behaviour, only a shared shape.
- A worker never throws across the channel. It wraps `applyTo` and returns a `WorkerFailure` sentinel on any throw; the main process turns that sentinel into a fault locally. This keeps one failing operation from killing the single, un-restarted write worker and disabling the whole write path.
- A worker that receives anything other than a command or query has been handed a broken protocol, not a failing operation. It replies with a `WorkerFailure` naming what it received and then ends its loop, because a worker that simply closed its channel would tell the main process nothing about why the write path stopped.

## Consequences

- Dispatch is polymorphic and open: a new operation is a new command class, with no shared switch or registry to edit. The worker loop never changes.
- A reviewer will see twenty-odd one-method classes and want to fold them into a generic command with a `match`, or back into direct repository calls. Both undo the decision: direct calls and closures cannot cross the process boundary, and a central `match` reintroduces the coupling the worker boundary removes. Do not consolidate them.
- Every field of every command must stay serialisable; adding a non-serialisable dependency to a command is a design error that will only surface at the channel.
- Failures travel as values (`WorkerFailure`) up to the main-process adapter, which converts them to a fault; the single write worker survives a failing command. The `Worker*` persistence adapters that forward these commands must fail fast on an unexpected worker result rather than returning a default.
- The transaction granularity for batched event writes (ADR-0005), the CLI tools that bypass the worker entirely (ADR-0008), and the cached stat read taken on the main connection (ADR-0009) all build on this command/worker model.
