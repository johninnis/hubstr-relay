# 23. `HubstrPolicy` is a host implementation of `RelayPolicyInterface`, not a reuse of the library's `RelayPolicy`

## Status

Accepted

## Context

`innis/nostr-relay` decides every access question through one port, `RelayPolicyInterface`, and ships a built-in implementation, `RelayPolicy`. Hubstr Relay supplies its own, `HubstrPolicy`. Set side by side, the two share their tenant plumbing almost line for line: the check that a connection has authenticated as a tenant, the check that an event p-tags a tenant, and the early returns that exempt a tenant from subscription caps, filter scoping, delivery scoping and rate limits. A reader who diffs them sees duplication and reaches for reuse.

Reuse is not available, for reasons that are structural rather than incidental.

`RelayPolicy` takes a `RelayPolicyConfig` whose tenant set is fixed when the policy is constructed. Hubstr's tenants are not fixed: they are added and removed at runtime over the management API, and every decision reads the current set from `PolicyState` (ADR-0006). A policy built once over a snapshot would answer for the tenants of start-up, not of now. `RelayPolicy` is also `final`, and extending it to override the tenant source would be the wrong tool even were it open: the two are two strategies behind one port, and a strategy is injected, not inherited.

Delegation fares no better. Wrapping `RelayPolicy` with a synthesised config on every call would rebuild its `GuestFilterRules` per decision on the hot path, and would still leave every Hubstr-specific rule outside it: the content blacklist, the NIP-57 receipt check (ADR-0015), the per-kind global read set (ADR-0013), the tag-prefix provenance (ADR-0018, ADR-0019), and the NIP-46 challenge offer (ADR-0014). Those are the substance of the policy, and none of them has a home in a static-config policy.

What can be shared already is. The scoping of a guest's filters and the delivery check are the library's `GuestFilterRules`; the subscription caps are the library's `SubscriptionLimits`. `HubstrPolicy` holds neither of those rules itself.

## Decision

`HubstrPolicy` implements `RelayPolicyInterface` directly, reading tenants and guest policy from `PolicyStateInterface` on every call and composing the library's `GuestFilterRules` and `SubscriptionLimits` for the parts they cover. The built-in `RelayPolicy` is a convenience for a relay whose policy is static configuration; it is not a base, a delegate, or a reuse target for this one.

## Consequences

- The tenant plumbing that both policies carry is duplicated across the two packages by design. It is the cost of two strategies behind one port, and the overlap is small: a membership check and its early returns.
- Do not extend `RelayPolicy`, and do not wrap it with a config rebuilt per call. The first is closed by the class and by composition over inheritance; the second re-derives the guest rules on every decision and still leaves the Hubstr rules outside.
- The one piece of genuine reuse still open is the tenant membership check itself, which both policies could take as an injected collaborator. That is a change to `nostr-relay`, and until the library offers it the check lives here.
- Should the library's `RelayPolicy` ever take its tenant set from a provider rather than a snapshot, this record is the place to revisit whether `HubstrPolicy` can compose it; the Hubstr-specific rules above would still have to sit in front of it.
