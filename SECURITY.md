# Security

This document describes the security properties Hubstr Relay provides, the properties it deliberately leaves to the operator, and the reasoning behind the non-obvious decisions. It is the reference an operator should read before exposing this relay to the internet, and the reference a contributor should read before changing any authentication, admission or management-API path.

Hubstr Relay is a deployable service. Unlike a library, it has an attack surface the moment it is running: a public WebSocket endpoint that strangers connect to by design, and a management API that mutates the relay's policy.

## Audit status

**This relay has not undergone an independent third-party security audit.** It is built and reviewed with care, with the design decisions recorded in [`docs/adr/`](docs/adr/) and an integration test that drives hostile client traffic under live policy changes on every CI run, but internal review is not a substitute for an external audit. Treat these guarantees as best-effort and not externally verified. If you commission or perform an audit, please share the results through the vulnerability-reporting channel below.

## Reporting a vulnerability

If you have found a security vulnerability in Hubstr Relay, report it privately through GitHub's built-in vulnerability reporting: **Security → Advisories → Report a vulnerability** on the repository page. Do not open a public issue for security-sensitive bugs.

Include:

- A description of the vulnerability and its impact.
- Reproduction steps or a proof-of-concept.
- The affected version (tag or commit SHA).

Acknowledgement is best-effort within 72 hours. Fixes land first, then the advisory is published.

For non-security bugs, open a regular issue.

This project does not run a bug bounty.

## Supported versions

Only the latest tagged release is supported. Older releases do not receive backported fixes, ever. Use the latest version.

## Security properties

### What this relay provides

- **The management API is authenticated by signature, not by origin.** Every call carries a NIP-98 event binding the request URL, the method and a hash of the body, signed by a configured tenant key. A valid signature from a key that is not a tenant is refused.
- **A management request cannot be replayed.** The relay records the id of every auth event it accepts and refuses a second use of it, so an observed `Authorization` header is not reusable inside its validity window. Each request is signed afresh.
- **Only a tenant can authenticate over NIP-42.** Completing the handshake with any other key widens nothing and is refused. Authentication exists to lift a tenant to full scope, not to admit arbitrary clients. See [ADR-0004](docs/adr/0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md).
- **A guest sees the tenant's public presence and nothing else.** Guest reads are scoped by kind and, by default, by author. Private mail is excluded twice over: the wrapper's ephemeral author fails the tenant author gate, and the gift-wrap kind is kept out of the readable set as defence in depth. See [ADR-0017](docs/adr/0017-gift-wraps-are-excluded-from-the-guest-readable-kinds.md).
- **A protected event is accepted only from its authenticated author.** A `-` tagged event (NIP-70) relayed by anyone else is refused, tenants included, so an event its author confined to one relay cannot be republished here through a trusted connection. See [ADR-0032](docs/adr/0032-a-protected-event-is-published-only-by-its-authenticated-author.md).
- **Resource limits and the content blacklist bind a tenant too.** A tenant is exempt from the subscription and filter caps, which bound concurrency. It is not exempt from the read ceiling or the content-length limit, because a single oversized request costs the process, not the caller, and it is not exempt from the blacklist, because a ban is a statement about what the relay holds rather than about who is trusted. See [ADR-0004](docs/adr/0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md) and [ADR-0031](docs/adr/0031-the-size-limit-and-the-content-blacklist-are-checked-ahead-of-the-tenant-bypass.md).
- **Every query is parameterised.** SQL is confined to the persistence store classes. Values reach the engine as bound parameters, never as interpolated text; where a statement has a variable number of placeholders, the placeholders are generated from a count and the values are still bound.
- **Database work never runs on the event loop.** Reads and writes are dispatched to worker processes as serialisable command objects, so a slow or hostile query cannot stall the connection loop for every other client. See [ADR-0010](docs/adr/0010-database-work-runs-on-worker-processes.md).
- **A COUNT stops at the configured ceiling and says so.** An unauthenticated COUNT over a popular tag would otherwise visit every matching row, with the client choosing how many there are. The reply is marked approximate when it stops, so a client cannot mistake a ceiling for a total. See [ADR-0024](docs/adr/0024-a-count-stops-at-the-row-ceiling-and-is-reported-approximate.md).
- **The statement cache cannot pin the write connection.** No method hands a caller an open cursor, so a single-row lookup cannot hold a read lock and silently stop the database checkpointing. This is enforced by the shape of the runner's surface and pinned by a test. See [ADR-0020](docs/adr/0020-prepared-statements-never-leave-the-statement-runner.md).
- **Expired events are removed from storage, not merely hidden.** A sweep deletes them on a timer, so an event with a passed expiry stops occupying disk and stops being counted, aggregated or exported. See [ADR-0028](docs/adr/0028-expired-events-are-swept-from-storage-not-filtered-on-read.md).
- **An address block outlives the reason recorded with it.** The length cap on a block's reason is applied when the block is submitted, never when stored blocks are loaded, so a block already in the database is enforced whatever its reason says. See [ADR-0026](docs/adr/0026-a-block-outlives-the-reason-written-with-it.md).

### What this relay does not provide

- **TLS.** The relay serves plain HTTP and WebSocket and expects a reverse proxy in front of it. Without TLS the management API's signed request, and every event a client reads, are on the wire in clear. Serve it over TLS.
- **Origin restriction on the management API.** The API answers any origin with a wildcard CORS header, deliberately, so that any Nostr client can call it. The signature is the authentication; the origin is not. See [ADR-0001](docs/adr/0001-wildcard-cors-on-management-api.md).
- **Protection of the tenant key.** Anyone holding a tenant key can read everything the relay stores and change every policy it enforces. Prefer a remote signer over a key in browser storage.
- **A correct client address without configuration.** Rate limiting and address blocking key on the address the relay sees, which is the proxy's unless `trusted_proxies` names your proxy. Misconfigure it and every client shares one bucket, or a client forges its own address.
- **Protection against a hostile tenant.** A tenant is the operator. Do not configure a key as a tenant unless you would hand that key the relay.
- **Spam filtering beyond the configured rules.** The content blacklist, the guest write rules and the address blocks are what the relay enforces. Deciding that a well-formed, correctly-signed, policy-compliant event is unwanted is not automated.
- **Any undo for a ban.** Banning a word deletes every stored event containing it, irreversibly. The relay refuses a word shorter than three characters because such a fragment matches most ordinary text, but it cannot tell a deliberate broad ban from a mistyped one. Treat a ban as destructive.
- **Multi-process or multi-host operation.** Runtime state, including authenticated sessions, subscriptions and the policy projection, lives in one process. Running two copies against one database is not a supported configuration.
- **Encryption at rest.** The SQLite database holds every event the relay received, including gift wraps, in plain form. Protect the file and its backups.

## Design decisions

### The management API is origin-agnostic on purpose

An origin allowlist would break every legitimate client without stopping an attacker, who does not use a browser. What protects the API is that each call carries a fresh NIP-98 signature from a tenant key over the exact URL, method and body. Do not "harden" this by adding an allowlist. See [ADR-0001](docs/adr/0001-wildcard-cors-on-management-api.md).

### NIP-46 Connect is readable by anyone, and that is a considered exception

Nostr Connect has two directions with different authors: a client's request is written by an ephemeral key no tenant gate would pass. Making that kind readable regardless of author is what lets a signer work at all. The exposure is bounded because the payloads are encrypted and the kind is named explicitly rather than the author gate being relaxed generally. Kind 1059 is deliberately not in that set. See [ADR-0013](docs/adr/0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md).

### A malformed zap receipt is refused ahead of the tenant bypass

The NIP-57 check runs before the check that lets a tenant through, so a receipt that fails validation is refused whoever sent it. A receipt is a claim about a payment; an invalid one is malformed rather than unauthorised, and is reported as such. See [ADR-0015](docs/adr/0015-nip57-invalid-zap-receipts-are-rejected-as-invalid-events.md).

### The last tenant cannot be removed, and the guard is in the delete

The guard is a condition on the delete statement itself rather than a check the caller performs first, because a check-then-delete has a window in which another writer removes the other tenant. There is exactly one place that can lock a relay out of its own management API, and it is atomic. See [ADR-0021](docs/adr/0021-the-last-tenant-guard-is-a-conditional-delete-on-the-write-worker.md).

### An unparseable policy rule is refused, never defaulted away

When the management API is given a guest policy it cannot parse, it returns an error rather than silently substituting a default. A policy that quietly becomes something other than what was sent is how a relay ends up more open than its operator believes. See [ADR-0018](docs/adr/0018-guest-writes-can-be-admitted-by-tag-value-prefix.md).

### Worker pools are killed, not drained

On shutdown the pools are killed rather than allowed to finish. Derived statistics recompute from the base tables, and each event is stored in its own transaction, so an interrupted worker loses no committed data and leaves nothing half-applied. See [ADR-0022](docs/adr/0022-worker-pools-are-killed-at-stop.md).
