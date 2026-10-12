# 23. `HubstrPolicy` is a host implementation of `RelayPolicyInterface`, not a reuse of the library's `RelayPolicy`

## Status

Accepted

## Context

`innis/nostr-relay` decides every access question through one port, `RelayPolicyInterface`, and ships a built-in implementation, `RelayPolicy`. Hubstr Relay supplies its own, `HubstrPolicy`. Set side by side, the two share their tenant plumbing almost line for line: the check that a connection has authenticated as a tenant, the check that an event p-tags a tenant, and the early returns that exempt a tenant from subscription caps, filter scoping, delivery scoping and rate limits. A reader who diffs them sees duplication and reaches for reuse.

Reuse is not available, for reasons that are structural rather than incidental.

`RelayPolicy` takes a `RelayPolicyConfig` whose tenant set is fixed when the policy is constructed. Hubstr's tenants are not fixed: they are added and removed at runtime over the management API, and every decision reads the current set from `PolicyState` (ADR-0006). A policy built once over a snapshot would answer for the tenants of start-up, not of now. `RelayPolicy` is also `final`, and extending it to override the tenant source would be the wrong tool even were it open: the two are two strategies behind one port, and a strategy is injected, not inherited.

Delegation fares no better. Wrapping `RelayPolicy` with a synthesised config on every call would rebuild its `GuestFilterRules` per decision on the hot path, and would still leave every Hubstr-specific rule outside it: the content blacklist, the NIP-57 receipt check (ADR-0015), the per-kind global read set (ADR-0013), the tag-prefix provenance (ADR-0019, ADR-0044), and the NIP-46 challenge offer (ADR-0014). Those are the substance of the policy, and none of them has a home in a static-config policy.

What can be shared already is. The scoping of a guest's filters and the delivery check are the library's `GuestFilterRules`; the subscription caps are the library's `SubscriptionLimits`. `HubstrPolicy` holds neither of those rules itself. Some rules are not a policy's at all: the library applies them after whatever any policy answers, the NIP-70 author rule on writes (ADR-0037) and the confinement of gift wraps to the keys they name on reads (nostr-relay ADR-0036, shared nostr-adrs ADR-0122), which the store applies from the reader's acting keys (ADR-0043).

## Decision

`HubstrPolicy` implements `RelayPolicyInterface` directly, reading tenants and guest policy from `PolicyStateInterface` on every call and composing the library's `GuestFilterRules` and `SubscriptionLimits` for the parts they cover. The built-in `RelayPolicy` is a convenience for a relay whose policy is static configuration; it is not a base, a delegate, or a reuse target for this one.

A tenant's exemption from filter and delivery scoping is this policy's answer, and it stops where the library's own rules begin: after `filterForClient` the library hands the store the keys the connection authenticated as, and on every filter the store matches a gift wrap only where its `p` tag names one of them. The policy never sees or sets that scope. A tenant therefore reads the wraps addressed to it and no other tenant's, whatever this policy grants.

## Consequences

- A tenant cannot read another tenant's gift wraps; `GuestAccessControlTest` pins it. Do not answer it in the policy as well: the library's rule binds every policy, and a copy here would be a second place the rule could drift.
- The tenant plumbing that both policies carry is duplicated across the two packages by design. It is the cost of two strategies behind one port, and the overlap is small: a membership check and its early returns.
- Do not extend `RelayPolicy`, and do not wrap it with a config rebuilt per call. The first is closed by the class and by composition over inheritance; the second re-derives the guest rules on every decision and still leaves the Hubstr rules outside.
- The one piece of genuine reuse still open is the tenant membership check itself, which both policies could take as an injected collaborator. That is a change to `nostr-relay`, and until the library offers it the check lives here.
- Should the library's `RelayPolicy` ever take its tenant set from a provider rather than a snapshot, this record is the place to revisit whether `HubstrPolicy` can compose it; the Hubstr-specific rules above would still have to sit in front of it.
- Shared decision: nostr-adrs ADR-0122.
