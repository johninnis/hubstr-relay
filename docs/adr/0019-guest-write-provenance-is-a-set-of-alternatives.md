# 19. Guest write provenance is a set of alternatives

## Status

Accepted

## Context

A guest event has to prove it belongs on the relay, and `GuestWritePolicy` offers two ways to demand that proof. `tagged_to_tenant` says *this event is addressed to the operator*: it carries a `p` tag naming a tenant. The tag prefix rules of [ADR-0044](0044-a-tag-prefix-rule-admits-only-the-kinds-it-lists.md) say *this event names something the relay carries*: it is of one of a rule's kinds and carries the rule's tag with a value starting with one of its prefixes.

Every other clause a guest write meets is a conjunct — too large, blacklisted, wrong kind: each must pass. The obvious move is to make these two conjuncts as well, so that enabling both demands both.

Nothing does both. A gift wrap tags a tenant and names no page; a page comment names a page and tags no tenant. Under AND, a relay with both checks enabled admits neither, so one relay could not be a direct-message inbox and a page-comment host at once. The only way to run a comment host would be to switch `tagged_to_tenant` off, which is a workaround dressed as configuration and gives up the inbox.

The two checks are not two hurdles. They are two different answers to one question — *why does this event belong here?* — and an event needs one answer, not all of them.

## Decision

**Whichever provenance checks are configured are alternatives.** `tagged_to_tenant` covers every writable kind; a tag prefix rule covers only the kinds it lists (ADR-0044). An event meeting any configured check is admitted, and an event of a writable kind is admitted with no check at all when neither is configured: `tagged_to_tenant` off and no rule.

**The kind gate is not an alternative; it remains a conjunct.** It is a precondition rather than a proof of provenance: if an allowed kind were sufficient on its own, no tag rule could ever gate anything within the kinds it lists, and the policy would collapse back to a kind list.

**`GuestWritePolicy` owns the whole decision.** `GuestWritePolicy::admissionFailure(EventKind, TagCollection, PublicKeyCollection $tenants)` checks the kind gate and then the alternatives, and returns null for an event it admits or a `GuestWriteFailure` naming the part that failed: `KindNotWritable`, or the alternatives the event did not meet, `NoTenantReference`, `NoRecognisedTag` or `NoTenantReferenceNorRecognisedTag`, so the rejection tells the client what it would have had to do. `HubstrPolicy::allowEventSubmission` keeps what is its own, the blacklist, the NIP-57 receipt check and the tenant bypass, and maps the failure to a rejection: `blocked:` with the failure's message for an authenticated client, `auth-required:` otherwise. A relay with only one check enabled behaves exactly as a conjunct would.

Tenants bypass provenance with everything else, through the early return for an authenticated tenant or a tenant-authored event.

## Consequences

- One relay can host page comments and take direct messages, with `tagged_to_tenant` and a prefix rule both on.
- The rejection message names what the event would have had to do, and reads as alternatives when both checks are configured: `event must reference a relay tenant or carry a recognised identifier tag`. An unauthenticated client is told to authenticate instead, as for any other guest rejection.
- No schema change: the guest policy is a JSON blob, and nothing about its shape depends on this.
- Do not turn the provenance checks into conjuncts "for consistency with the other clauses". The other clauses are limits every event must respect; these are alternative proofs, and demanding all of them admits nothing.
- A third provenance check, should one be added, joins the same set of alternatives in `admissionFailure`, with a `GuestWriteFailure` case for each combination it can leave unmet, rather than becoming a new clause in `allowEventSubmission`. Whether the relay advertises NIP-17 asks the same method ([ADR-0042](0042-nip17-is-advertised-while-the-guest-policy-accepts-gift-wraps-addressed-to-a-tenant.md)), so a new check reaches the advertisement without a second edit.
