# 24. A COUNT stops at the row ceiling and is reported approximate

## Status

Accepted

## Context

A guest subscription is bounded in what it costs. The filters are scoped to tenant authors and readable kinds, and the store stops at the client's `limit`, capped by `max_limit`, so the most a REQ can make a read worker do is fetch that many rows. A NIP-45 COUNT admits the same filters through the same gate (nostr-relay ADR-0006) but asked the store for an exact total, and an exact total has no ceiling: the store visits every matching row, and the client chooses how many there are. Measured at two million events, a guest COUNT over fifteen popular hashtags cost 1.1 seconds of a read worker where the equivalent REQ cost 7 milliseconds. With four read workers and sixty subscriptions a minute per address, a handful of addresses could hold the pool for everyone.

NIP-45 lets a relay answer approximately and say so. nostr-core models the reply as an `EventCount` that is exact or approximate (nostr-core ADR-0064), and nostr-relay's store port returns that value (nostr-relay ADR-0013); what this record decides is where the ceiling sits and how it is reported.

A second question comes with the ceiling: what a COUNT of several filters means. A REQ over a filter set returns the union of what the filters match, each event once, because a client asked one question through several shapes. Counting each filter separately and adding the totals answers a different question, and over filters that overlap it answers it wrongly — the relay would report more events than the same filter set would deliver, and could report a number larger than the ceiling it just enforced.

## Decision

`EventQueryStore::countByFilters` gathers the ids each filter matches, at most `max_limit` of them per filter, and counts the union. That is the same set, de-duplicated the same way, that `findRawJsonByFilters` returns for those filters, so the store answers one question one way. What the relay then does with that set still differs on one point: a REQ withholds an event that has expired since it was stored and a count does not (ADR-0028).

The reported number never exceeds `max_limit`, the same ceiling a REQ may return. The answer is approximate when any filter reached the ceiling, or when the union is larger than it: the relay then sends `"approximate": true` with the capped count. Below the ceiling the count is exact and the key is absent.

The ceiling is one number for every client, tenant or guest. A tenant wanting a true total has `getstats` over the management API, which is computed off the hot path and cached (ADR-0012).

## Consequences

- A COUNT costs at most what the REQ it could have been would cost; the caps that already bound reads now bound counts too.
- Counting the union costs ids where counting rows cost only a number. That is the price of the two answers agreeing, and the ceiling bounds it: at most `max_limit` ids per filter, over at most the filters a subscription may carry.
- Do not go back to summing per-filter totals. It is cheaper by one column and wrong whenever two filters overlap.
- A client can never mistake a ceiling for a total, because the reply says which it received.
- Do not raise the count ceiling independently of `max_limit`, and do not give tenants an uncapped count "because they are trusted": the cost is the same whoever asks, and the totals a tenant needs are served from the cache.
- Do not drop the approximate flag to make replies uniform. A capped count without it is a wrong answer.
