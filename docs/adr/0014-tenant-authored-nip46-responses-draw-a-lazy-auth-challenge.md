# 14. Tenant-authored NIP-46 responses are admitted and draw a lazy AUTH challenge

## Status

Accepted

## Context

This record complements [ADR-0013](0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md), which admits an unauthenticated signer in the first place; this one is about what happens to its reply stream once admitted.

A remote signer (`hubstr-signer`) acting as a bunker publishes a kind-24133 response for every NIP-46 request. An app logging in behind an auto-approve pairing fires a burst of auto-answered requests (many `nip44_decrypt`, `get_public_key`, a sign or two), so the signer emits a matching burst of responses. Per [ADR-0013](0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md) the signer connects **unauthenticated**, so those responses hit the per-IP Events token bucket and the tail comes back `OK false "rate-limited: slow down"` — dropped, never delivered.

The rate limiter exempts a connection only when `isRateLimitExempt(client)` → `isTenant(client)` is true, i.e. the connection has NIP-42-authenticated as a tenant pubkey. Nothing else in the relay's posture would ever send the signer an AUTH challenge:

- The signer's `#p` subscription is a global read kind, within guest scope, so it draws no challenge (ADR-0013).
- Its responses are tenant-authored, so `HubstrPolicy::allowEventSubmission` admits them via `isEventFromTenant($event)` with no challenge.
- Challenges are issued lazily, never on connect (`nostr-relay` ADR-0004).

A signer therefore has no route to the exemption unless one is opened deliberately: every path that would normally trigger a challenge is, for this client, within scope.

Two constraints shape the answer:

- **DoS ordering.** The rate limiter runs before signature verification precisely so a flood is shed cheaply. Exempting the bucket *per event* by author would require verifying the signature before the limiter, letting an attacker spoof a tenant pubkey to force unbounded verifications. So the proof of key-holding must happen **once per connection** (NIP-42), not per event.
- **ADR-0013 must hold.** An unauthenticated tenant signer is a supported first-class client ("publishes signed responses without NIP-42"). Rejecting its responses until it authenticates would reverse that. Delivery must continue.

## Decision

`HubstrPolicy` offers an AUTH challenge on exactly one case: a tenant-authored kind-24133 arriving on a connection that has not authenticated. Admission is unchanged — `allowEventSubmission` still admits that event as before — so the challenge is an offer made *alongside* delivery, never a condition of it.

This separates the decision from its effect, mirroring the subscription path where the policy returns `ScopedFilters` and the library both admits and challenges. The seam it relies on — a policy hook the library consults after admitting an event, and which drives the same messenger and challenge registry the subscription path already uses — belongs to `nostr-relay` and is recorded there; this record is about which case Hubstr answers `true` for, and why that case and no other.

The signer's client signs the challenge with the unlocked key, authenticates as its (tenant) pubkey, and from then on `isTenant(client)` holds, so the connection is rate-limit exempt and its responses flow unthrottled. A signer that never authenticates keeps working exactly as under ADR-0013 — its responses are delivered, subject to the rate limit.

This keeps the lazy, activity-triggered challenge posture of `nostr-relay` ADR-0004 (the challenge is drawn by a write that warrants auth, never on connect) and preserves ADR-0013 (delivery never depends on authenticating). The proof of key-holding is per connection, so the DoS ordering (rate-limit cheap-and-first, signature verify second) is untouched: the offer lives after `allowEventSubmission`, so only admitted, sig-verified events draw a (cheap, random) challenge.

## Consequences

- An unauthenticated tenant signer keeps working, rate-limited, as under ADR-0013. Wiring NIP-42 on the signer is **optional** and purely earns rate-limit exemption — nothing regresses if it is absent.
- Only tenant-authored **kind 24133** draws the challenge. Tenant-authored notes, zap receipts, and other kinds are unaffected; a client's own request (non-tenant author) draws nothing.
- The challenge is offered once per connection and delivery is immediate (no reject/retry round trip). A cold burst may spend a few Events tokens before auth completes; negligible, since auth is one local round trip and the bucket starts full.
