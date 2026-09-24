# 7. Guest-readable kinds are the tenant's public presence

## Status

Accepted

## Context

Guest reads default to `from_tenants_only=true`: an unauthenticated client only sees an event of a readable kind when a **tenant authored (signed)** it. Read scope is author-based, never p-tag-based — the write path is the mirror, where a guest may publish a writable kind only if it p-tags a tenant (`tagged_to_tenant`). Authenticated tenants bypass the readable-kinds gate entirely and read every kind.

So the question for each candidate kind is not "is this kind sensitive in general" but "is the tenant's **own** instance of it something the public should read". Without that framing the set gets chosen by scanning NIPs for anything that looks harmless, which admits kinds that are harmless in the abstract and wrong for this relay, and rejects kinds that look sensitive but are, in the tenant's own hands, simply public.

## Decision

`GuestReadPolicy::DEFAULT_KINDS` is the tenant's public presence plus the discovery metadata needed to find and render it. Three groups qualify:

- **Public broadcast content** the tenant published — metadata (0), notes (1), reposts (6, 16), reactions (7), pictures (20), video (21, 22), comments (1111), highlights (9802), long-form (30023).
- **Discovery and list metadata** meant to be world-readable — follows (3), relay list (10002), the DM relay list (10050), the NIP-51 lists and sets (10000, 10001, 10003, 10015, 10030, 30003, 30004, 30030) whose sensitive entries live encrypted in `.content`, and the Blossom media-server list (10063).
- **Public payments**, attributable by design — nutzaps (9321) and zap receipts (9735).

Kind 10050 earns its place under the second group specifically: it is how a sender discovers where to deliver a tenant's NIP-17 direct messages, and it carries relay URLs only — a public pointer, never message content. Without it the relay cannot advertise itself as a DM inbox.

**Governing rule for future edits.** A kind belongs in the defaults only if the tenant's own instance of it is public: broadcast content, discovery metadata, or attributable public payments. Inbound-private kinds, and kinds encrypted *to* the tenant, do not belong here however harmless the kind number looks; the tenant reads those over NIP-42 at full scope. The exclusion that rule produces for gift wraps, and the defence-in-depth reasoning behind it, are recorded separately in [ADR-0017](0017-gift-wraps-are-excluded-from-the-guest-readable-kinds.md) — that exclusion is a privacy invariant with a far longer expected life than this list.

The one encrypted kind that is nonetheless guest-readable, kind 24133 (NIP-46 Connect), is a conscious exception recorded in [ADR-0013](0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md): it is ephemeral, never stored, its payload is encrypted, and its residual metadata leak is accepted so that unauthenticated signers work.

## Consequences

- A guest sees the tenant's public life and nothing else: a readable kind is only ever readable when the tenant signed it.
- The relay advertises itself as a NIP-17 inbox, because a guest can read the tenant's kind-10050 DM relay list to discover where to send that tenant's messages.
- This list is expected to change as NIPs land, and changing it is a routine edit governed by the rule above. It does not disturb the gift-wrap exclusion, which is recorded on its own precisely so this list can move without dragging a privacy argument along with it.
- Do not widen the set by reasoning about a kind in the abstract. Ask only whether the *tenant's own* instance of it is public.
