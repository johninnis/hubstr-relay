# 17. Gift wraps are excluded from the guest-readable kinds as defence in depth

## Status

Accepted

## Context

Kind 1059 (NIP-59 gift wraps carrying NIP-17 direct messages) is the most sensitive kind this relay stores. It is not the tenant's broadcast; it is private mail addressed *to* the tenant, so under the governing rule in [ADR-0007](0007-guest-readable-kinds-are-the-tenants-public-presence.md) it has no claim on the readable set.

The subtle part is that the author gate already hides it, which makes the naive posture — leave 1059 readable and rely on that gate — look sufficient. NIP-59 mandates that the outer wrapper is signed by a fresh single-use ephemeral key, never the sender's identity. An ephemeral key is never a configured tenant, so under `from_tenants_only=true` a gift wrap is never author-matched and is invisible to guests whether or not 1059 is a readable kind.

Resting the privacy of the most sensitive kind on the relay on a single setting is fragile. `from_tenants_only` is operator-configurable at runtime; set it to `false` and every readable kind becomes readable from any author, which would expose every stored direct message to any anonymous connection. The setting has legitimate uses and an operator flipping it is not doing anything obviously reckless — which is exactly why the consequence must not be catastrophic.

## Decision

Kind 1059 is **not** in `GuestReadPolicy::DEFAULT_KINDS`. Two independent gates therefore hide gift wraps from guests, and either alone is sufficient:

- **The author gate** — the wrapper's ephemeral signer is never a tenant, so `from_tenants_only=true` never matches it.
- **The kind gate** — 1059 is absent from the readable kinds, so it is rejected for guests regardless of what `from_tenants_only` is set to.

The recipient tenant loses nothing, because no guest can read a gift wrap under either gate: the tenant authenticates over NIP-42 and reads its `#p` mailbox at full scope.

1059 stays in the guest **writable** defaults (`GuestWritePolicy::DEFAULT_KINDS`, `tagged_to_tenant=true`) so any sender can deliver a direct message to a tenant without authenticating. Delivery and receipt are unaffected by this record.

**Kind 1059 must never be added to `global_kinds`.** A global kind counts as readable *and* bypasses the author gate ([ADR-0013](0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md)), so putting 1059 there defeats both gates at once. This is the one action the kind-gate exclusion does not protect against, which is why it is stated as a standing prohibition rather than left as an inference.

## Consequences

- Direct-message privacy rests on two independent gates rather than one setting. Setting `from_tenants_only=false` does not expose stored gift wraps.
- Message confidentiality itself comes from NIP-17/NIP-59 client-side encryption and ephemeral authorship, not from relay logic. This record protects metadata and ciphertext-at-rest from casual retrieval; it is not what makes a direct message secret.
- Neither gate covers an operator re-adding 1059 through the management API — to `read.kinds`, which falls back to the author gate alone, or to `global_kinds`, which defeats both.
- There is deliberately no gift-wrap-specific filter anywhere in the policy. Do not add one expecting it to improve privacy: the two gates are structural, and a special case for one kind would be a third code path protecting nothing the first two miss.
- Editing the readable-kind list in ADR-0007 is a routine change and does not touch this record. Removing 1059's exclusion is not routine: it reverses this record's decision, and has to be made here, deliberately, never as a side effect of editing that list.
