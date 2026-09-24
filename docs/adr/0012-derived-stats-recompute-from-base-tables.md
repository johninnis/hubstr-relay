# 12. Derived stats are recomputed from base tables, never incrementally maintained

## Status

Accepted

## Context

The relay serves explore and analytics figures — trending hashtags, most-followed, most-muted, most-zapped, top zappers, most-reacted-to, most-reposted — plus a totals payload for the management API.

The obvious way to feed a stats dashboard is a counter table: a per-pubkey row of tallies (following, mutes, reactions, reposts, zaps sent and received with their sat totals) that the denormaliser increments on every matching event insert. Reads then become a single indexed lookup, and the cost is spread across the write path instead of being paid at read time.

That shape is wrong here, for two reasons.

**An incremental counter is only honest if every mutation path decrements it.** Events do not only arrive: they are deleted by NIP-09, purged when a pubkey, word or hashtag is banned, and superseded when a newer replaceable event replaces an older one. A counter maintained on insert alone drifts away from the facts, and the drift is unbounded and invisible — the number looks authoritative and is simply wrong. Keeping it honest means every delete, purge and supersede path must find and decrement every counter it affects, which couples all of those paths to the stats feature and is easy to get subtly wrong.

**The read path does not need an incremental feed.** Totals and explore stats are computed by scheduled refresh tasks on worker processes (ADR-0010) and cached in `stat_results` (ADR-0009). Nothing computes them on the hot path, so even a full-scan aggregate costs the event loop nothing. The write-path cost a counter table exists to avoid buys nothing that is actually needed.

There is also a definitional trap. A per-pubkey counter table needs a rule for which pubkeys get a row, and any rule expressible from the denormaliser's code paths — "authored a follow or mute list, sent or received a zap, or was the first p-tag of a reaction or repost" — produces a population nobody can explain on a dashboard.

## Decision

Derived statistics are always recomputed from the base tables at refresh time. No incrementally maintained counter tables exist.

- There is no `profile_stats` table, and the denormaliser maintains no per-pubkey counters. Reactions and reposts are not denormalised at all. Follow lists, mute lists, relay lists and zap receipts populate their fact tables (`profile_follows`, `profile_mutes`, `profile_relays`, `zap_receipts`), which hold **facts, not tallies**.
- `known_pubkeys` is defined as the number of distinct event authors — `COUNT(DISTINCT pubkey) FROM events` — one indexed scan, executed off the event loop on a worker process (the scheduled totals refresh, or a read worker when the cache is empty) and served from the `stat_results` cache. It means "distinct authors we hold events from", and deliberately excludes pubkeys that are only referenced by a zap or a follow list but never stored as an author.

## Consequences

- Derived numbers cannot drift. They are recomputed from facts at each refresh, so a delete, purge or supersede is reflected automatically at the next refresh, with no decrement logic anywhere.
- The event write path stays light: a reaction or repost insert touches no denormalised table.
- Every mutation path is decoupled from the stats feature. A new delete or purge path needs no corresponding counter maintenance.
- A refresh pays a full aggregate scan. That is affordable precisely because it runs off the hot path on a worker and its result is cached; it would not be affordable inline.
- A future per-profile stats feature must follow the same pattern — a recomputing query over the base tables, cached in `stat_results` if it is expensive — not a counter table. Do not introduce incremental counters without first solving decrement-on-delete for *every* mutation path; that unsolved problem is what makes counters untrustworthy.
