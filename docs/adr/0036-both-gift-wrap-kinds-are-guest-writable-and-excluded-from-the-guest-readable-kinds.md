# 36. Both gift-wrap kinds are guest-writable and excluded from the guest-readable kinds

## Status

Accepted

Supersedes ADR-0017, which decided that kind 1059 is guest-writable when it tags a tenant, is kept out of the guest-readable kinds as defence in depth, and must never be a global kind. That decision is carried forward unchanged. What is revised is its reach: it now covers kind 21059, the ephemeral gift wrap, on exactly the same terms. This record holds the complete current decision.

## Context

*Carried forward from ADR-0017.* Kind 1059 (NIP-59 gift wraps carrying NIP-17 direct messages) is the most sensitive kind this relay stores. It is not the tenant's broadcast; it is private mail addressed *to* the tenant, so under the governing rule in [ADR-0007](0007-guest-readable-kinds-are-the-tenants-public-presence.md) it has no claim on the readable set.

The subtle part is that the author gate already hides it, which makes the naive posture — leave 1059 readable and rely on that gate — look sufficient. NIP-59 mandates that the outer wrapper is signed by a fresh single-use ephemeral key, never the sender's identity. An ephemeral key is never a configured tenant, so under `from_tenants_only=true` a gift wrap is never author-matched and is invisible to guests whether or not 1059 is a readable kind.

Resting the privacy of the most sensitive kind on the relay on a single setting is fragile. `from_tenants_only` is operator-configurable at runtime; set it to `false` and every readable kind becomes readable from any author, which would expose every stored direct message to any anonymous connection. The setting has legitimate uses and an operator flipping it is not doing anything obviously reckless — which is exactly why the consequence must not be catastrophic.

*What this record adds.* NIP-59 defines a second wrapper: "An ephemeral gift wrap is a `kind:21059` event. It has the same structure as `kind:1059` but follows ephemeral event semantics (relays MUST NOT store it)." It is "intended for real-time applications that do not require asynchronous operations and where messages are only relevant to currently connected recipients". ADR-0017 admitted guest writes of 1059 only, so a sender could not deliver a live gift wrap to a tenant without authenticating, although the payload, the one-time wrapper key and the `p` tag naming the recipient are the same. Because 21059 is never stored, a guest could only ever see one as it passes through the relay to live subscribers; the read gates that decide that are the same ones that guard a stored 1059.

## Decision

Kinds 1059 and 21059 are the gift-wrap kinds, and every rule below applies to both.

They are **not** in `GuestReadPolicy::DEFAULT_KINDS`. Two independent gates therefore hide gift wraps from guests, on the stored read path and on live delivery alike, and either alone is sufficient:

- **The author gate** — the wrapper's ephemeral signer is never a tenant, so `from_tenants_only=true` never matches it.
- **The kind gate** — the gift-wrap kinds are absent from the readable kinds, so they are rejected for guests regardless of what `from_tenants_only` is set to.

The recipient tenant loses nothing, because no guest can read a gift wrap under either gate: the tenant authenticates over NIP-42 and reads its `#p` mailbox at full scope, or holds a live subscription for it.

Both kinds are in the guest **writable** defaults (`GuestWritePolicy::DEFAULT_KINDS`, `tagged_to_tenant=true`), so any sender can deliver a direct message to a tenant without authenticating. 1059 was writable already; 21059 is added.

**No gift-wrap kind may ever be added to `global_kinds`.** A global kind counts as readable *and* bypasses the author gate ([ADR-0013](0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md)), so putting 1059 or 21059 there defeats both gates at once. This is the one action the kind-gate exclusion does not protect against, which is why it is stated as a standing prohibition rather than left as an inference.

Kind 21059 is never stored. nostr-relay's accepted-event pipeline publishes an event in the ephemeral range (20000–29999) to live subscribers and returns `OK` without calling the event store, and the CLI import skips an ephemeral event rather than writing it.

## Consequences

- Direct-message privacy rests on two independent gates rather than one setting. Setting `from_tenants_only=false` does not expose stored gift wraps, nor ephemeral ones in transit.
- Message confidentiality itself comes from NIP-17/NIP-59 client-side encryption and ephemeral authorship, not from relay logic. This record protects metadata and ciphertext from casual retrieval; it is not what makes a direct message secret.
- Neither gate covers an operator re-adding a gift-wrap kind through the management API — to `read.kinds`, which falls back to the author gate alone, or to `global_kinds`, which defeats both.
- There is deliberately no gift-wrap-specific filter anywhere in the policy. Do not add one expecting it to improve privacy: the two gates are structural, and a special case for one kind would be a third code path protecting nothing the first two miss.
- A live gift wrap to a tenant reaches only the tenant's authenticated subscriptions open when it arrives. One sent while the tenant is offline is lost, which is what NIP-59 intends for 21059.
- Whether NIP-17 is advertised still follows kind 1059 alone ([ADR-0033](0033-nip17-is-advertised-only-while-the-guest-policy-makes-the-relay-an-inbox.md)): NIP-17's inbox is persistent mail, and 21059 is not part of it.
- Editing the readable-kind list in ADR-0007 is a routine change and does not touch this record. Removing a gift-wrap kind's exclusion is not routine: it reverses this record's decision, and has to be made by superseding it, deliberately, never as a side effect of editing that list.
