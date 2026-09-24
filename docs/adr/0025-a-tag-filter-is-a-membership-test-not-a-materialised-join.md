# 25. A tag filter is a membership test, not a materialised join

## Status

Accepted

## Context

A NIP-01 tag filter (`#t`, `#p`, `#e`, ...) selects events carrying one of a list of tag values. The obvious SQL joins the events table to a subquery that first collects the distinct event ids of every matching tag row. SQLite evaluates that subquery as a co-routine and runs it to completion before the outer query sees a row, so the cost of the filter is the number of matching tag rows, whatever else the filter says and however few rows the caller wants. Measured at two million events, a filter over fifteen popular hashtags cost about a second in that shape: for a REQ asking for fifty rows, for a COUNT, and for a guest whose filter was already scoped to a single tenant's events.

The same filter written as a membership test, `event_id IN (SELECT event_id FROM event_tags WHERE ...)`, leaves the planner free to drive from the events side when another clause makes that cheap. With the filter scoped to an author the planner walks that author's events through their index and probes the tag index per event, and the second becomes nothing. Where no other clause helps, it materialises the subquery as before and costs roughly what the join cost.

A correlated `EXISTS` test was measured too. It is faster still on scoped and popular filters, but it always drives from the events side, so a tag value that matches nothing, or only old events, makes it walk the whole table: five seconds at two million events for a value that matches nothing. Planner statistics from `ANALYZE` change none of these plans, because SQLite's default statistics carry no per-value selectivity.

## Decision

`EventQueryStore` expresses every tag filter as `e.event_id IN (SELECT event_id FROM event_tags WHERE tag_name = ? AND tag_value IN (...))`, one condition per tag name, with the parameters in the order the conditions appear.

## Consequences

- A guest's tag filter, which the policy scopes to tenant authors by default, costs milliseconds instead of a second, and the COUNT ceiling of ADR-0024 now bounds what it was meant to bound.
- An unscoped filter over popular tag values still materialises the matching tag rows and costs on the order of a second at two million events. That case is reachable by an authenticated tenant, or by a guest only when the operator has switched `from_tenants_only` off. Bounding it needs a choice between plans based on how many tag rows match, which no single SQL shape gives; it is not made here.
- Do not rewrite the test as a correlated `EXISTS` for the speed it shows on popular tags. Its cost is unbounded on the other side, and a filter for a value that matches nothing must stay free.
