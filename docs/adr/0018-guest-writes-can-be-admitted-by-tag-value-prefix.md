# 18. Guest writes can be admitted by tag value prefix

## Status

Accepted

## Context

A relay hosting comments for a web site needs to admit exactly one thing: events that name a page of that site. NIP-22 puts the page in the root scope of a kind 1111 comment as an uppercase `I` tag whose value is the page URL, per NIP-73's external content identifiers.

The other two admission clauses in `GuestWritePolicy` cannot express that.

`kinds` is too coarse. Kind 1111 is every comment on every subject in the protocol, so a relay that admits it admits comments about anything.

`tagged_to_tenant` is the wrong shape. It requires a `p` tag naming a tenant, which a NIP-22 comment does not carry — the comment names a *page*, not a person. Requiring one would mean only clients that know the operator's pubkey could comment, which defeats the purpose of using a standard kind at all: any nostr client writing an ordinary NIP-22 comment would be refused.

Without a third clause the operator's only options are to admit kind 1111 from everyone about everything, or not to accept comments.

## Decision

`GuestWritePolicy` carries a `TagPrefixRequirementCollection`: a list of rules, each a `TagPrefixRequirement` naming one tag and a list of value prefixes. A rule is met by an event that carries that tag with a value starting with any one of its prefixes, and **the list is satisfied when any one rule is**. How the list combines with `tagged_to_tenant` is a separate decision, recorded in [ADR-0019](0019-guest-write-provenance-is-a-set-of-alternatives.md).

**The tag name is configuration, not `I`.** The rule is about tag values, and nothing in it knows about NIP-22, NIP-73 or web pages. A relay confining guests by `t`, or by a scheme other than a URL, uses the same clause.

**It is a list because one tag name is not enough, and any-one because requiring them all admits nothing.** NIP-22 puts the root item in the uppercase tags and the immediate parent in the lowercase ones. A top-level comment's parent *is* the page, so it carries both; a reply parents on the comment above it and carries `e` and `k` instead, with no lowercase `i` at all. The uppercase `I` is therefore the only tag present on every event in a comment thread, and it is the one to name for comments. But every *other* event that references a page does so through plain NIP-73, which is lowercase: a kind 1 note citing a URL carries `i` and `k` and no uppercase tag, and so does a kind 17 reaction to external content — kind 7 being reserved for reactions to nostr events. A relay admitting comments *and* notes *and* page reactions needs `I` or `i`: two rules sharing one prefix list, either of which admits. Requiring both would admit top-level comments alone and refuse every reply, note and reaction — a comment section nobody can reply in.

**The match is exact and case-sensitive.** A URL's host is case-insensitive while its path is not, and normalising one would mean this value object growing a URL parser and a notion of scheme — knowledge an admission rule has no business holding. Case folding is the publishing client's job, and NIP-73 asks for normalised identifiers already.

**The default is an empty list, meaning no requirement.** On the wire the key is `write.tag_prefixes`, an array of `{tag, prefixes}` objects; an absent key, a `null` and an empty array all mean no requirement.

**It applies to writes only.** There is deliberately no read equivalent. `filterForClient` scopes a guest by rewriting filter *fields* — `GuestFilterRules` constrains authors and kinds because both are things a NIP-01 filter can say. A prefix is not: tag filters match by exact value, so there is no rewritten subscription that means "only `I` tags starting with this". The alternative, dropping events after the store has selected them, breaks `limit` — a guest asking for a hundred events would receive however many survived, with no way to page for the rest.

The write rule makes the read rule unnecessary anyway. If nothing can be deposited without a site-prefixed tag, everything a guest could read already satisfies the prefix. Confinement happens at the door, where it is expressible.

**A submitted rule that does not parse is refused, not defaulted.** `GuestPolicy::fromArray` stays total and lenient, as the rest of the policy is; `TenancyRpcHandler::setGuestPolicy` returns `400` when `write.tag_prefixes` is present but any entry in it is unparseable, and stores nothing. Leniency is right for a kind list, where a dropped entry narrows what is allowed. It is wrong here, where a dropped rule *widens* it: an operator who mistypes the key would get a relay admitting everything while believing it constrained.

A requirement with no prefixes, or with an empty one, cannot be constructed — an empty prefix matches every value, so it is a rule that reads as a constraint and behaves as its absence.

## Consequences

- A relay can host comments for one site without admitting kind 1111 from the whole protocol, and without requiring commenting clients to know the operator's pubkey. A site relay admits comments (`I`), notes referencing a page (`i`) and kind 17 reactions (`i`) with two rules sharing one prefix list.
- No schema change. The guest policy is a JSON blob in `settings`, so the key round-trips through `toArray`/`fromArray`.
- No new NIP-86 method. `setguestpolicy` and `getguestpolicy` carry the key in the object they already exchange.
- `GuestWritePolicy`'s constructor is at three arguments, which is the ceiling. A fourth admission clause is a signal to split the type rather than to add a parameter.
- **Kind 7 is inadmissible under any prefix rule.** A reaction to a comment carries `e` and neither identifier tag, because what makes it legitimate is that its target already satisfied the rule — a join an admission check cannot do. A relay wanting reactions on comments must either leave kind 7 out of the writable kinds, or gain per-kind scoping of the rule. That is a further axis and wants its own record.
- Do not make the list all-required "to be stricter". No event carries every tag a useful list names.
- Do not add a read-side prefix rule "for symmetry". It cannot be enforced where the read policy is enforced, and the reason is in the Decision above.
- Do not make `fromArray` reject a malformed rule. The refusal belongs at the RPC boundary, which has a response to refuse with; `fromArray` is also the path `PolicyState` loads stored settings through, and a stored policy that throws would take the relay down at start-up rather than at the point someone got it wrong.
