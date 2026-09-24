# 32. A protected event is published only by its authenticated author

## Status

Accepted

## Context

NIP-70 lets an author mark an event with a `-` tag to say that only they may publish it, and asks a relay that understands the tag to refuse the event from anyone else. The point is to stop a third party lifting an event from one relay and republishing it on another: a note meant for one audience, or a relay-specific message, stays where its author put it. A relay that does not implement the rule accepts such an event from whoever relays it, which is exactly the behaviour the tag exists to prevent, so a client is told to publish a protected event only to a relay that advertises 70.

This relay already has both facts the rule needs at the point of admission. `HubstrPolicy::allowEventSubmission` sees the event and the set of pubkeys the connection has authenticated as. The question is only where in the method the rule sits.

It cannot sit after the tenant bypass. The bypass admits a tenant-authored event from any connection, so a tenant's protected note relayed by a stranger would pass, and it admits anything an authenticated tenant submits, so a tenant's client relaying somebody else's protected event would pass too. Both are the republishing NIP-70 forbids. The rule is about who is publishing the event, not about whether the publisher is trusted, and trust is what the bypass grants.

## Decision

A protected event is admitted only when the connection has authenticated as the event's author. The check runs ahead of the tenant bypass, beside the size limit and the content blacklist recorded in [ADR-0031](0031-the-size-limit-and-the-content-blacklist-are-checked-ahead-of-the-tenant-bypass.md).

An unauthenticated connection is answered `auth-required: this event may only be published by its author`, which draws the relay's lazy AUTH challenge so the author can prove the key and resend. A connection authenticated as some other key is answered `blocked:` with the same message, because authenticating again would change nothing.

The relay advertises 70 in its NIP-11 document.

## Consequences

- An author publishes a protected event to this relay by authenticating first. Every other route, including a tenant relaying it, is refused.
- Only a tenant can authenticate here ([ADR-0004](0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md)), so in practice a guest cannot publish a protected event at all. That is the correct reading of the two rules together: a guest can prove nothing about a key, and a protected event demands proof.
- Do not move the check below the tenant bypass so that a tenant can post protected events without authenticating. The bypass answers "is this publisher trusted"; this rule answers "is this publisher the author", and the second cannot be inferred from the first.
- `HubstrPolicyTest` pins the unauthenticated refusal, the wrong-key refusal, the tenant-relaying refusal and the authenticated-author admission.
