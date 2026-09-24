# 4. NIP-42 is a tenant-only scope-lift offer, never a connection gate

## Status

Accepted

## Context

Hubstr Relay embeds `innis/nostr-relay` and supplies its access policy through `HubstrPolicy` (implementing the library's `RelayPolicyInterface`). NIP-42 AUTH could be used to gate every connection until the client proves its identity — the obvious "secure default".

Hubstr's primary read/write workload is NIP-46 (Nostr Connect, kind 24133) traffic between a tenant's bunker and ephemeral client apps, and most NIP-46 client apps in the wild do not implement NIP-42. A connection-gating challenge — or challenging ordinary clients at all — would break them.

The relay library only emits an AUTH challenge when a request exceeds the guest scope the policy defines, never on connect (this is the library's decision; see nostr-relay ADR-0004, "An AUTH challenge is issued only when a request exceeds guest scope, never on connect"). This record states how Hubstr configures that scope and what authentication means here.

## Decision

NIP-42 is a tenant-only offer to lift to full scope, never a connection gate.

- **Hubstr never gates a connection.** It relies on the relay's scope-triggered challenge and adds no connect-time AUTH.
- **Only a tenant may authenticate.** `HubstrPolicy::allowsAuthentication()` refuses every pubkey that is not a configured tenant; a non-tenant that completes the AUTH handshake is rejected (`restricted: authentication is limited to relay tenants`). Authentication exists to lift a tenant — e.g. a bunker reading its own request mailbox — to full scope, not to admit arbitrary clients. Answering the challenge gains nothing unless the answering key is a tenant.
- **A scope lift is not a volume lift.** `filterForClient()` clamps every filter to the configured `max_limit` before it checks whether the client is a tenant, so the ceiling holds for a tenant too. A tenant is exempt from `max_subscriptions`, `max_filters` and rate limiting, which bound how much concurrent work a client sets going and can be left to a trusted operator's judgement. The read ceiling bounds one reply instead, and an unbounded one is nobody's intent: a tenant filter matching a million stored events would have the relay read, scope and serialise a million events into a single subscription. A tenant reading more than the ceiling pages through it with `until`, one bounded read at a time.
- **Guest scope is shaped so the challenge fires only for a tenant reading its own restricted data.** With the default `from_tenants_only=true`, a guest sees only tenant-authored events; the one filter that exceeds that scope is a `#p` mailbox filter referencing a tenant — the bunker reading the ephemeral-authored requests addressed to it. Ordinary NIP-46 client apps publish requests (a writable kind, tagged to a tenant) and read tenant-authored responses (within guest scope), so they never exceed scope and are never challenged.

## Consequences

- NIP-42-incapable NIP-46 clients keep working as guests; the only party ever prompted to AUTH is the tenant's own bunker, which can answer.
- Authentication is identity-bearing and tenant-only: completing the AUTH handshake with a non-tenant key widens nothing.
- An authenticated tenant's REQ returns at most `max_limit` events per filter, the same as a guest's, and finds that number published as `max_limit` in the relay-information document. Do not move the clamp below the tenant check to complete the exemption; nostr-relay ADR-0019 records why that limit is deliberately not among them.
- The readable-kinds set is recorded in ADR-0007, the gift-wrap exclusion in ADR-0017, and the residual NIP-46 metadata leak in ADR-0013.
- Do not add a connect-time challenge, and do not let non-tenants authenticate — either change re-breaks the NIP-46 client workload this posture exists to support.
