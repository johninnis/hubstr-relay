# 6. `PolicyState` is a mutable in-process read model

## Status

Accepted

## Context

The codebase is functional-first: a unit's output depends only on its inputs, and data flows through arguments and return values, not shared mutable state or instance-held collaborators. `PolicyState` contradicts that default — it is a `final` (non-`readonly`) class whose tenant set, guest policy, blacklist, rate limits, blocked IPs and metadata overrides are mutated in place. A reviewer applying the functional rule would be tempted to make it immutable and rebuild it on every change, or to read each value from the database on demand.

Two forces make the mutable projection the right call here:

1. **Reads are on the hot path and must not cross a process boundary.** The relay runs on an amphp event loop, and SQLite allows only one writer, so all database work is pushed to worker processes to keep blocking I/O off the loop. But the access policy is consulted on *every* event submission, subscription and delivery — `HubstrPolicy` asks "is this pubkey a tenant?", "is this event blacklisted?", "what is the guest policy?" inline, and `PolicyState` also serves the `ConnectionGateInterface` (IP gate, NIP-11 metadata overrides). Routing each of those checks through a read-worker channel round-trip would serialise the event loop's policy decisions behind cross-process I/O — the same reason the `CachingStatsProvider` and `CachingExploreQuery` decorators read their cache hit on the main connection rather than through a worker: a synchronous read on the hot path must not pay a cross-process round-trip. The authoritative policy must be readable synchronously, in-process, in nanoseconds.

2. **Durability is already handled elsewhere, asynchronously.** `PolicyState` is not the system of record — the SQLite tables are. It is a read model loaded once at bootstrap (`loadFromDatabase()`) and kept current by `WriteThroughPolicyManagement`, the `final readonly` write-through coordinator: every mutation first persists a `WriteCommandInterface` command through the single write worker, then updates the in-memory projection so subsequent reads observe the change immediately. The mutation never originates in `PolicyState`; it only ever lands there after being durably recorded.

An immutable `PolicyState` would force one of two worse shapes: a whole-object rebuild-and-swap behind a holder on every ban/tenant/setting change (ceremony with no behavioural gain, since the process is single-threaded and the swap is not observable mid-operation), or per-read database access that reintroduces the cross-process cost point 1 exists to avoid.

## Decision

`PolicyState` is a deliberately mutable in-process read model of durable policy state, scoped to the single main process and mutated **only** through `WriteThroughPolicyManagement`'s write-through methods (persist first, then update memory). It is the one sanctioned exception to the no-shared-mutable-state rule, justified by hot-path synchronous reads over an asynchronously-persisted projection.

The projection also holds one value derived from it: the `GuestFilterRules` built from the tenant set and the guest read policy, which decides both what a guest subscription is narrowed to and whether a live event may be delivered to a guest. It is memoised here and discarded whenever the tenants or the guest policy change, so the per-delivery check allocates nothing and both read paths always ask the same object.

## Consequences

- Policy checks on the hot path are synchronous in-process reads; no worker round-trip, no event-loop stall.
- The invariant that keeps this safe is **persist-then-mutate in `WriteThroughPolicyManagement`**: the in-memory state may never diverge from the database by being mutated without a corresponding committed `WriteCommandInterface` command. A mutator on `PolicyState` called from anywhere but `WriteThroughPolicyManagement` is a bug. The one other caller is the `HostileSessionChurn` test fixture, which runs against an in-memory database with no write worker and drives the mutators directly to churn policy under load; it has no durable state to diverge from.
- The projection is rebuilt from the database on every process start, so a missed in-memory update is self-healing on restart but not before — correctness depends on every write going through `WriteThroughPolicyManagement`.
- Do not "functionalise" `PolicyState` into an immutable value object, and do not make its reads go to the database or a worker. Either change trades away the hot-path synchronous read this design exists to provide. If immutability is ever wanted, it must be a whole-state atomic swap behind a holder that preserves synchronous in-process reads — not per-field `with*()` transformation threaded through the policy call sites.
