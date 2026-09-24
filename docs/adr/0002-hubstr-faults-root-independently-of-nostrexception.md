# 2. Hubstr faults root independently of `NostrException`

## Status

Accepted

## Context

Hubstr Relay depends on the Nostr libraries (`innis/nostr-core` and `innis/nostr-relay`), which throw faults rooted at `NostrException`. Because the dependency graph points from Hubstr to Nostr, the obvious-looking move is to root Hubstr's own exceptions under `NostrException` too, so the whole process has a single throwable root.

## Decision

Faults are rooted by **whose code raises them, not by the dependency graph**.

- Nostr library faults (e.g. `RelayException`) surface as `NostrException`-rooted and are not re-rooted as they pass through Hubstr Relay.
- Hubstr Relay's own faults root at `HubstrException`, the abstract base (extending `\Exception`) that `innis/hubstr-core` provides for every Hubstr service, which does **not** extend `NostrException` even though the relay depends on the Nostr libraries. Its leaves are the faults of Hubstr's own concerns — the stats cache, the worker protocol, a stored event row that will not decode, and a stored setting that will not decode.
- **What decides the root is the concern the fault is about, not the file it is thrown from.** A fault asserting a *Nostr protocol* rule is raised in the library's own vocabulary and travels the library's own handling path, so it stays `NostrException`-rooted even when Hubstr's code raises it. ADR-0015 records the one place that arises and why it is not re-rooted.
- The hierarchy is deliberately small, because anticipated outcomes — a well-formed operation whose answer is "no" — are returned as typed values (`?T`, outcome enums, `*Failure` objects) rather than thrown. There is no exception for "that was the last tenant" or "that pubkey is not a tenant" because the analyser can force a caller to handle a returned value and cannot force it to catch.

## Consequences

- A `catch (NostrException)` catches only library faults; it never accidentally swallows a Hubstr-originated fault, and vice versa. The root identifies the origin.
- Hubstr cannot rely on a single shared throwable root across both layers; the process boundary catches `\Throwable` to render a 500 / log.
- Do not "unify" the hierarchies by extending `HubstrException` from `NostrException` — the dependency direction is not the deciding factor.
