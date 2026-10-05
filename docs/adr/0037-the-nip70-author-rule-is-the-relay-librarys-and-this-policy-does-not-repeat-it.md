# 37. The NIP-70 author rule is the relay library's, and this policy does not repeat it

## Status

Accepted

Supersedes ADR-0032, which decided that a protected event is admitted only from a connection authenticated as its author, checked ahead of the tenant bypass. That decision is carried forward. What is revised is where the rule lives, which is now the relay library and no longer this policy, and the prefix of the wrong-key refusal. This record holds the complete current decision.

## Context

NIP-70 says "The default behavior of a relay MUST be to reject any event that contains `["-"]`", and a relay that accepts one "MUST first require that the client perform the NIP-42 `AUTH` flow and then check if the authenticated client has the same pubkey as the event being published and only accept the event in that case."

ADR-0032 put that rule in `HubstrPolicy`, ahead of the tenant bypass, because the bypass would otherwise admit a tenant's protected event from a stranger, or another author's protected event from a tenant. innis/nostr-relay now applies the rule itself, after whatever policy it is given, so no policy's bypass can admit a protected event from anyone but its authenticated author (innis/nostr-relay ADR-0023). Keeping the check here as well would state one NIP requirement in two places, which drift.

## Decision

- A protected event (one carrying the tag `["-"]`) is admitted only when the connection has authenticated as the event's author, as innis/nostr-relay decides it. `HubstrPolicy` does not test for protection.
- An unauthenticated connection is answered `auth-required: this event may only be published by its author`, which draws the relay's AUTH challenge so the author can prove the key and resend.
- A connection authenticated only as other keys is answered `restricted:` with the same words, the prefix NIP-42 gives "for when a client has already performed `AUTH` but the key used to perform it is still not allowed by the relay". ADR-0032 answered it `blocked:`.
- The event validator's refusals, the size limit among them (ADR-0039), and the policy's own (the content blacklist of ADR-0039, the zap-receipt check of ADR-0015, the guest write rules) are answered before the author rule.
- The relay advertises 70 in its NIP-11 document.

## Consequences

- An author publishes a protected event to this relay by authenticating first. Every other route, including a tenant relaying it, is refused.
- Only a tenant can authenticate here ([ADR-0004](0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md)), so in practice a guest cannot publish a protected event at all.
- Do not add a protected-event check back to `HubstrPolicy`: the library's rule already binds every policy, and a second copy is the drift this record removes.
- `HubstrPolicyTest` pins, through the submission use case, the tenant-relaying refusal, the unauthenticated refusal with its challenge, and the authenticated author's event being stored.
