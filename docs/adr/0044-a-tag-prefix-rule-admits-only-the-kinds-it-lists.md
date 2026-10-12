# 44. A tag prefix rule admits only the kinds it lists

## Status

Accepted

Supersedes ADR-0018. Carried forward unchanged: a guest write can be admitted by a list of tag prefix rules, any one of which admits; the tag name is configuration; the match is exact and case-sensitive; there is no read-side rule; a submitted rule that does not parse refuses the whole submission with `400`; no rule can hold an empty prefix. Revised: each rule names the kinds it admits, required and never empty, and admits no other kind; and a stored rule that no longer parses stops the relay at start-up, where ADR-0018 dropped it on load. This record holds the complete current decision.

## Context

*Carried forward from ADR-0018.* A relay hosting comments for a web site needs to admit exactly one thing: events that name a page of that site. NIP-22 puts the page in the root scope of a kind 1111 comment as an uppercase `I` tag whose value is the page URL, per NIP-73's external content identifiers. `kinds` is too coarse to say that, because kind 1111 is every comment on every subject in the protocol. `tagged_to_tenant` is the wrong shape, because it requires a `p` tag naming a tenant, which a NIP-22 comment does not carry: the comment names a page, not a person.

NIP-22 puts the root item in the uppercase tags and the immediate parent in the lowercase ones. A top-level comment's parent is the page, so it carries both `I` and `i`; a reply parents on the comment above it and carries `e` and `k`, with no lowercase `i`. Every other event that references a page does so through plain NIP-73, which is lowercase: a kind 1 note citing a URL carries `i` and `k`, and NIP-25 says a reaction to external content "MUST be a `kind 17` event and MUST include NIP-73 external content `k` + `i` tags". A relay admitting comments, notes and page reactions therefore needs two rules, `I` and `i`, either of which admits.

*What this record adds.* Under ADR-0018 a rule applied to every writable kind. The writable kinds are the union of everything any route admits, so a rule meant for page comments also admitted a gift wrap addressed to a stranger, a NIP-46 request or a zap receipt, as long as its sender added the page tag. A sender chooses an event's tags; the rule was only ever meant to say which comments, notes and reactions belong here. The relay then held a wrap for nobody it serves, or relayed a request for a signer it does not host.

Two shapes could scope it. A per-kind provenance map would say, for each writable kind, which checks admit it, and would move `tagged_to_tenant` into that map as well. Scoping each rule to the kinds it is for keeps `tagged_to_tenant` as it is, covering every writable kind, and states the scope where the rule is declared, which is where an operator decides what the rule is for.

ADR-0018 also decided that a stored policy whose rule does not parse loads with the rule dropped, so that a mistake would not take the relay down at start-up. That held while a stored rule could only be malformed by hand. It does not hold now: every rule an earlier release stored lacks `kinds`, there is no migration, and dropping such a rule on load leaves a relay with `tagged_to_tenant` off admitting every guest event of a writable kind, the widening ADR-0018 already refused to guess at when a policy is submitted.

## Decision

- **A rule names its tag, its prefixes and its kinds.** `TagPrefixRequirement` holds a `TagType`, a list of prefixes and an `EventKindCollection`. It is met by an event of one of its kinds that carries its tag with a value starting with any one of its prefixes. The list is met when any one rule is. How the list combines with `tagged_to_tenant` is [ADR-0019](0019-guest-write-provenance-is-a-set-of-alternatives.md): the two are alternatives, `tagged_to_tenant` covers every writable kind, and a rule only its own.
- **A rule's kinds are required and never empty.** On the wire a rule is `{"tag": "I", "prefixes": [...], "kinds": [1111]}`. `TagPrefixRequirement::tryFromArray` refuses a rule without `kinds`, with an empty list, or with an entry that is not an integer event kind, and the constructor refuses an empty list. A kind listed more than once is held once, in the order first listed, so the stored policy and `getguestpolicy` carry it once; the repetition admits nothing more, so it is not refused. There is no default to every kind, nor to the writable kinds: either would reopen the hole this record closes.
- **A rule may name a kind outside `write.kinds`.** The kind gate stays the precondition every guest write passes, checked first by `GuestWritePolicy::admissionFailure`; a rule's kinds narrow what the rule admits within it. A rule naming a kind that is not writable admits nothing for that kind until the kind is writable again. Refusing such a rule would make an edit to one list invalidate the other: an operator taking a kind out of `write.kinds` would have to edit every rule naming it in the same submission, and a stored policy would need the same cross-check on load.
- **The tag name is configuration.** The rule is about tag values, and nothing in it knows about NIP-22, NIP-73 or web pages. A site relay names `I` for kind 1111 and `i` for kinds 1 and 17, sharing one prefix list.
- **The match is exact and case-sensitive.** A URL's host is case-insensitive while its path is not, and normalising one would mean this value object growing a URL parser. Case folding is the publishing client's job, and NIP-73 asks for normalised identifiers already.
- **An empty list is no rule.** On the wire the key is `write.tag_prefixes`, a list of rules; an absent key, a `null` and an empty list all mean no rule is configured. That is no requirement only while `tagged_to_tenant` is off too (ADR-0019).
- **It applies to writes only.** A prefix is not something a NIP-01 filter can say, and dropping events after the store has selected them breaks `limit`. If nothing can be deposited without a site-prefixed tag, everything a guest could read already satisfies the prefix.
- **One parser, strict with the rules.** `GuestPolicy::tryFromArray` returns null when `write.tag_prefixes` is present and any rule in it does not parse, and stays lenient with the kind lists, where a dropped entry only narrows what is allowed. `TenancyRpcHandler::setGuestPolicy` answers null with `400` and stores nothing. `PolicyState` answers it, for the stored policy, by throwing `MalformedSettingException` naming the `guest_policy` setting, so the relay refuses to start rather than run with fewer rules than its operator configured.
- **No rule holds an empty prefix.** An empty prefix matches every value, so it is a rule that reads as a constraint and behaves as its absence.

## Consequences

- A page rule admits only the kinds it lists. A gift wrap addressed to a stranger, a kind 24133 request or a kind 9735 receipt carrying the page tag is refused; `HubstrPolicyTest` and `GuestWritePolicyTest` pin each, and that the rule still admits its own kinds.
- A relay whose stored guest policy has a rule from before this record does not start. It names the `guest_policy` setting, and its operator either adds `kinds` to each rule in the stored JSON, in the `settings` table, or deletes that row to start from the default policy and sets the rules again through `setguestpolicy`. No migration ships, because the kinds a rule was meant for are the operator's to say, not something the relay can infer.
- `WriteThroughPolicyManagementTest` pins that such a stored rule stops the load, and `RpcHandlerTest` that a submitted rule without kinds, or with none listed, is refused with `400`, and that a kind listed twice is stored and returned once.
- `GuestWritePolicy`'s constructor is still at three arguments. A fourth admission clause is a signal to split the type rather than to add a parameter.
- Kind 7 is still inadmissible under any prefix rule. A reaction to a comment carries `e` and neither identifier tag, because what makes it legitimate is that its target already satisfied a rule; scoping rules by kind does not change that.
- Do not default a rule's kinds to every kind or to the writable kinds, and do not read an empty list as either.
- Do not make the list all-required "to be stricter". No event carries every tag a useful list names.
- Do not add a read-side prefix rule "for symmetry". It cannot be enforced where the read policy is enforced.
- Do not make the stored load lenient again. A rule that is dropped on load widens what guests may write, silently.
