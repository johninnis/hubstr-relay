# 9. Cached stat lookups read on the main connection, not the read pool

## Status

Accepted

## Context

The relay keeps blocking SQLite I/O off the amphp event loop by pushing database work to worker processes. Reads go through a pool of read workers (`ReadWorkerPool`), each holding its own SQLite connection; filter queries, explore queries and web-of-trust scoring all run there. The standing rule is that database access happens on a worker, never on the main-process connection, so the event loop never blocks.

The caching decorators (`CachingStatsProvider`, `CachingExploreQuery`) wrap an expensive provider with a cache backed by the `stat_results` table. On the read path they consult `StatResultCache`, whose hit is `StatResultsStore::find()`, a single-row lookup keyed on the primary key `(stat_name, period)`. That store is constructed in `RelayContainer` over the main-process connection, so this one lookup deliberately does **not** go through the read pool. On a cache miss the decorator falls back to the wrapped provider, which **does** run on the read pool, and the cache persists the recomputed result through the single write worker.

Routing the cache hit through the read pool would, for what is a sub-millisecond indexed single-row read, add a cross-process channel round-trip and make the lookup contend for a read-worker slot against hot subscription queries. The round-trip and the queueing would cost more than the lookup itself, and the contention would slow the subscription workload the pool exists to serve. The cache is consulted to make explore and stats responses cheap; paying worker-pool overhead on every hit defeats the cache.

This is a deliberate exception to "all reads go through a worker". It reads like a smell — a raw main-connection query sitting beside a strict worker-pool discipline — so without a recorded reason a future reviewer would "fix" it by routing it through the pool and quietly regress the hot path. A single pointer comment at the construction site in `RelayContainer` references this record.

## Decision

The `stat_results` cache hit (`StatResultsStore::find()`) reads on the main-process connection, bypassing the read worker pool. Only the recompute-on-miss path goes through the read pool; the persist-on-miss path goes through the single write worker.

This is scoped narrowly to the single-row PK cache lookup. It is not a licence for other queries to run on the main connection — anything that scans, joins, or is not an indexed single-row lookup still belongs on the read pool.

## Consequences

- A cache hit on explore/stats is a synchronous in-process indexed read with no cross-process round-trip and no contention for a read-worker slot.
- The exception is bounded: it applies only to the primary-key single-row `find()`. Misses still recompute on the read pool and persist through the write worker, so the heavyweight work stays off the main process.
- This is the same hot-path-synchronous-read reasoning that justifies the mutable in-process policy projection in ADR-0006; the two exceptions share a rationale but are recorded separately because they protect different reads.
- Do not move this lookup onto the read pool to make read access uniform: a worker round-trip plus slot contention costs more than the lookup it would replace. Equally, do not generalise the exception to non-trivial queries.
