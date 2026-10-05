# 39. The size limit is the event validator's, and the content blacklist is checked ahead of the tenant bypass

## Status

Accepted

Supersedes ADR-0031. Carried forward unchanged: the content blacklist binds every author, tenant included, and is answered `blocked:`; the size limit binds a tenant too. Revised: where the size limit is applied and how it is answered. This record holds the complete current decision.

## Context

`HubstrPolicy::allowEventSubmission` answers one question for every inbound event: may this client write this event here. Most of its rules are about the client's standing. A guest may publish only a writable kind, and only with provenance; a tenant, or anyone relaying a tenant-authored event, is exempt from both, through an early return halfway down the method. A reader who finds that return will want to move it to the top, so that a tenant is exempt from everything. Several rules run before it and therefore bind a tenant too: the NIP-57 receipt check (ADR-0015) and the content blacklist of banned words, pubkeys and hashtags.

ADR-0031 also put the `max_content_length` ceiling there. It never took effect above 65536 characters: innis/nostr-core's `EventValidator` runs before the policy and refused anything longer with a constant of its own, so an operator who raised the limit advertised a number the relay did not honour. innis/nostr-core now takes its event limits as host configuration (its ADR-0132), and innis/nostr-relay builds its validator from `RelayConfigInterface::getEventLimits()` (its ADR-0025).

## Decision

- **The size limit is the event validator's.** `RelayConfig::getEventLimits()` returns `RelayLimits::toEventLimits()`, whose maximum content length is the configured `max_content_length`, and both the running relay and the `import` command validate every event with it. `HubstrPolicy` does not check content length. The validator runs before the policy for every connection, so the limit binds a tenant, and an oversized event is answered `invalid: Event content exceeds maximum length`, as every event the validator refuses is. The limit binds a tenant because the cost of an oversized event is the relay's whoever wrote it, and because the relay advertises it as `max_content_length` in its NIP-11 document.
- **The blacklist binds a tenant** because a ban is a statement about the relay's content, not about who is trusted. [ADR-0030](0030-a-content-ban-purges-through-the-filter-that-refused-the-write.md) makes a banned word mean that the relay holds nothing matching it, by refusing the write and purging what was stored through the same filter. If a tenant could write past the filter afterwards, that event would never meet the purge. The API refuses to ban a tenant pubkey (`TenantPolicyFailure::ActiveTenant`), so the only way a tenant meets the filter is through content it chose to publish. The refusal is `blocked:`, the same reason a guest receives, not `auth-required:`, because authenticating would change nothing.

## Consequences

- A configured `max_content_length` is the limit the relay applies, above 65536 as well as below it, and the NIP-11 document stays true for every author.
- An oversized event is answered `invalid:` where ADR-0031 answered `blocked:`.
- An operator who wants to publish a banned word unbans it first; there is no tenant exemption to reach for.
- Do not move the tenant bypass above the blacklist, and do not add a content-length check back to the policy.
- `HubstrPolicyTest` pins the blacklist refusal for an authenticated tenant, and an oversized event refused for a tenant and a longer configured limit honoured, through the submission pipeline.
