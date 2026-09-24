# 13. Global read kinds open NIP-46 Connect to unauthenticated signers

## Status

Accepted

## Context

Guest reads are author-scoped: with `from_tenants_only=true` an unauthenticated client sees an event of a readable kind only when a **tenant authored** it. That is the right default for the tenant's public presence, but it breaks NIP-46 Nostr Connect (kind 24133), whose two directions have different authors:

- **Signer → client response**: authored by the signer's (tenant) pubkey, so it is visible to guests.
- **Client → signer request**: authored by a **single-use ephemeral key**, which is never a tenant, so it is invisible to guests.

A remote signer (`hubstr-signer`) acting as a bunker destination in a `bunker://…?relay=` URL must read those client→signer requests. Requiring it to authenticate over NIP-42 first would work — a `#p` mailbox subscription is scope-exceeding and draws an AUTH challenge — but it makes NIP-42 a precondition for a signer working at all. The requirement here is that an **unauthenticated** signer works out of the box, and author-based read scope makes that impossible because the request author is never a tenant.

Flipping `from_tenants_only=false` would unblock it, but it is far too blunt: it opens guest reads of **every** readable kind to any author, when exactly one kind needs it.

## Decision

`GuestReadPolicy` carries a per-kind `global_kinds` set: kinds a guest may read **regardless of author**, bypassing `from_tenants_only`. In `GuestFilterRules` a filter confined to global kinds is not author-constrained and is not treated as scope-exceeding, so it draws no NIP-42 AUTH challenge (consistent with the `nostr-relay` library's rule that a challenge is issued only when a request exceeds guest scope: a global-only filter is within it). All other kinds stay author-scoped.

Default `global_kinds = [24133]`. Everything else — including kind 1059 — stays out of the set.

This is safe for 24133 specifically because:

- 24133 is ephemeral: the relay never stores it, so only live in-flight traffic is ever exposed, never a backlog.
- NIP-46 payloads are NIP-04/44 encrypted; a passive guest reads ciphertext it cannot decrypt.

The accepted cost is a metadata leak on both directions — a passive observer can correlate `(signer_pubkey, client_session_ephemeral_pubkey, timestamp)`. Content stays encrypted and client session keys are normally per-session disposable, so this is consciously accepted so that unauthenticated signers work.

## Consequences

- An unauthenticated remote signer can serve as a `bunker://` destination out of the box: it reads client→signer requests and publishes signed responses without NIP-42. A bunker **may** authenticate for a tighter posture but is not required to.
- A passive observer can correlate both directions of a NIP-46 session's metadata. Content stays encrypted.
- Kind 1059 is deliberately **not** a global kind. Do not add it (or other encrypted or author-sensitive kinds) to `global_kinds` — doing so makes gift wraps guest-readable and breaks [ADR-0017](0017-gift-wraps-are-excluded-from-the-guest-readable-kinds.md). The per-kind design exists precisely so 24133 can be opened without touching 1059.
- `global_kinds` is configurable at runtime via `setguestpolicy` (`read.global_kinds`); the write posture is untouched, so the app's request still passes only because it p-tags a tenant signer, and the signer's response passes as tenant-authored.
- Delivery depends on the ephemeral broadcast path: 24133 is fanned out to live subscribers, never stored, so the signer must be subscribed before a request is sent.
