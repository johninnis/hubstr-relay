# Hubstr Relay

[![CI](https://github.com/johninnis/hubstr-relay/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/hubstr-relay/actions/workflows/ci.yml)

A personal Nostr relay that serves as both a local event cache and a public-facing relay for your Nostr identity.

## What it does

Your browser-based Nostr client connects to other relays and forwards every event it receives into Hubstr Relay. The relay stores these events locally in SQLite, giving you a personal cache of your Nostr world.

It then serves events with two access tiers:

- **Anyone** can read your public events (notes, profile, reposts, reactions, long-form articles). They can also send you reactions, zaps, replies, and DMs.
- **You** (authenticated via NIP-42) get full access to the entire cache — every event your client has ever seen.

This means your relay is your public Nostr presence *and* your private event archive.

## How it works

```
┌─────────────┐     events from      ┌───────────────┐
│ Other relays │ ──── other relays ──→ │               │
└─────────────┘     via your client   │               │
                                      │  Hubstr Relay │ ← SQLite (WAL mode)
┌─────────────┐     your events,      │               │
│   Anyone     │ ←── public access ── │               │
└─────────────┘                       └───────────────┘
                                            ↑
┌─────────────┐     full cache,       ┌─────┘
│     You      │ ←── NIP-42 authed ───┘
└─────────────┘
```

The relay never connects outbound to other relays. It only receives events pushed to it and serves them back on request.

## Management

Relay policy is managed at runtime via NIP-86 (JSON-RPC over HTTP, authenticated with NIP-98). No config file changes, no restarts.

You can manage:
- **Tenants** — who gets full access (`allowpubkey`, `unallowpubkey`, `listallowedpubkeys`)
- **Guest policy** — what unauthenticated users can read and write
- **Blacklist** — blocked words, pubkeys, and hashtags
- **IP blocking** — block specific IP addresses
- **Rate limits** — events and subscriptions per minute
- **Relay metadata** — name, description, icon (reflected in NIP-11)
- **Monitoring** — connected clients, active subscriptions, event store stats
- **Explore & analytics** — trending hashtags, most-followed/muted/zapped/reacted/reposted, top zappers, and per-pubkey Web-of-Trust scores (`explore`, `getstats`, `getwotscore`), recomputed from the base fact tables by scheduled refresh tasks and cached

See [docs/MANAGEMENT_API.md](docs/MANAGEMENT_API.md) for the full API reference.

### Authentication and CORS

The management API uses NIP-98: every RPC call carries an `Authorization` header containing a Nostr event that binds the request URL, method, and SHA-256 hash of the body. The signer must be a configured tenant pubkey, and the event timestamp must be within 60 seconds of the server clock.

The HTTP endpoint responds with `Access-Control-Allow-Origin: *` so that any Nostr client — web, mobile, desktop, CLI — can call the management API. Always serve the relay over TLS, and prefer signing with a hardware/remote signer (NIP-46) over storing keys in browser localStorage. An observed `Authorization` header cannot be replayed: the relay remembers the id of every auth event it has accepted and refuses a repeat, so each request is signed afresh. See [ADR-0001](docs/adr/0001-wildcard-cors-on-management-api.md) for why CORS is wildcard.

## Content Filtering

Incoming events are checked against a blacklist (managed via the NIP-86 API):

- **Words** — events containing blacklisted words are rejected
- **Pubkeys** — events from blacklisted authors are rejected
- **Hashtags** — events with blacklisted hashtags are rejected

Blacklists are held in memory and updated immediately when changed via the API. They bind every author, tenants included: a ban is a statement about what the relay holds, not about who is trusted, so an operator who wants to publish a banned word unbans it first (see [ADR-0031](docs/adr/0031-the-size-limit-and-the-content-blacklist-are-checked-ahead-of-the-tenant-bypass.md)).

## Guest Policy Defaults

Out of the box, unauthenticated users can write:

- Kinds 1 (text notes), 7 (reactions), 1111 (comments), 9321 (nutzaps), 9735 (zap receipts), 1059 (gift wraps), 24133 (Nostr Connect) — each must carry a `p` tag naming a tenant pubkey

Guest reads default to `from_tenants_only=true`: an unauthenticated client only sees events authored by a tenant pubkey — **except** kinds in the `global_kinds` set, which guests may read regardless of author. Guest read kinds, the `global_kinds` set, the `from_tenants_only` flag, guest write kinds, and the tenant-tagging constraint are all configurable at runtime via `setguestpolicy`.

The read kinds and the global kinds together are the whole readable set: a guest may read a kind only if it is in one of them. Emptying the read kinds leaves only the global kinds readable, and emptying both means guests read nothing.

Global read kinds default to `[24133]` (NIP-46 Nostr Connect), so a remote signer can serve as a `bunker://` destination while connecting unauthenticated — it reads client requests and publishes signed responses without NIP-42. See [ADR-0013](docs/adr/0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md).

### Admitting guest writes by tag value

A third write clause, `tag_prefixes`, admits a guest event when it carries one of a configured set of tags with a value starting with one of that tag's prefixes. It is off by default.

This is what lets a relay host comments for one web site without admitting kind 1111 from the whole protocol. A NIP-22 comment names the page it is about in its root scope — an uppercase `I` tag holding the page URL, per NIP-73 — while a note referencing that page, or a kind 17 reaction to it, names it in the lowercase `i`. So a site relay configures both:

```json
[
  {"tag": "I", "prefixes": ["https://www.example.com/"]},
  {"tag": "i", "prefixes": ["https://www.example.com/"]}
]
```

**Any one rule satisfies the list**, and `tagged_to_tenant` and `tag_prefixes` are likewise alternatives rather than both-required — so one relay can take gift wraps addressed to you *and* comments naming your pages. The `kinds` list stays a precondition every guest write must pass.

The match is exact and case-sensitive, and there is no read equivalent — a prefix cannot be expressed as a NIP-01 filter, and the write rule makes one unnecessary. See [ADR-0018](docs/adr/0018-guest-writes-can-be-admitted-by-tag-value-prefix.md) and [ADR-0019](docs/adr/0019-guest-write-provenance-is-a-set-of-alternatives.md).

### Privacy of encrypted kinds (NIP-17, NIP-46)

Kind 1059 (NIP-59 gift wraps / NIP-17 DMs) is in the default guest **write** set but not the read set, and kind 24133 (NIP-46 Nostr Connect) is in both — so anyone can send you a DM or use a bunker without authenticating, while DM content stays encrypted and gift wraps stay unreadable by guests. Gift wraps are deliberately excluded from the readable kinds as defence in depth: even if `from_tenants_only` were flipped off, the kind gate still hides them. How the policy achieves that — and the residual NIP-46 metadata trade-off it accepts — is recorded in [ADR-0007](docs/adr/0007-guest-readable-kinds-are-the-tenants-public-presence.md) (the readable-kind set), [ADR-0017](docs/adr/0017-gift-wraps-are-excluded-from-the-guest-readable-kinds.md) (the gift-wrap exclusion) and [ADR-0013](docs/adr/0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md) (NIP-46 Connect). **Do not add kind 1059 to `global_kinds`** — it would make gift wraps guest-readable and break the ADR-0017 privacy property.

### Direct messages (NIP-17 inbox)

The relay works out of the box as a NIP-17 direct-message inbox for its tenant(s):

- **Receiving** — anyone can publish a kind 1059 gift wrap tagged to a tenant without authenticating (guest write set, `tagged_to_tenant`). NIP-59's randomised back-dated timestamps (up to two days) are accepted. A gift wrap is kept until it expires or is deleted; reading one does not remove it, so a client that fetches its inbox twice sees the same wraps.
- **Reading your inbox** — the recipient tenant authenticates over NIP-42 and reads its `#p` mailbox. A `#p`-for-tenant subscription is scope-exceeding, so the relay issues an AUTH challenge and, once authenticated, delivers the wraps at full scope.
- **Privacy** — no guest can read a gift wrap: the ephemeral wrapper author fails the tenant author gate, and kind 1059 is excluded from the readable kinds (see above).
- **Discovery** — publish a kind 10050 DM relay list naming this relay; it is guest-readable, so senders can find where to deliver your DMs.

Point your kind 10050 at this relay and it becomes your DM inbox. Message confidentiality is provided entirely by NIP-17/NIP-59 client-side encryption; the relay stores ciphertext and never parses it.

## Supported NIPs

| NIP | Description |
|-----|-------------|
| 1   | Basic protocol (EVENT, REQ, CLOSE, OK, EOSE, NOTICE) |
| 9   | Event deletion |
| 11  | Relay information document |
| 17  | Direct-message inbox: gift wraps addressed to a tenant are accepted from anyone and served only to that tenant behind AUTH. Advertised while the guest policy keeps it so |
| 40  | Event expiration: refused on arrival, withheld from reads, and swept from storage every ten minutes |
| 42  | Authentication |
| 45  | Event COUNT over the events the same filters would return, approximate beyond `max_limit` matches |
| 50  | Search (full-text via SQLite FTS5) |
| 70  | Protected events: a `-` tagged event is accepted only from a connection authenticated as its author |
| 86  | Relay management API |
| 98  | HTTP auth (management API authentication) |

These are the NIPs the relay advertises in its NIP-11 document. NIP-17 is the one that follows the runtime policy: it is listed only while gift wraps are guest-writable to a tenant and not guest-readable, so a sender's client is never told to deliver mail the relay would refuse or expose (see [ADR-0033](docs/adr/0033-nip17-is-advertised-only-while-the-guest-policy-makes-the-relay-an-inbox.md)). Only a tenant can authenticate here, so in practice only a tenant can publish a NIP-70 protected event (see [ADR-0032](docs/adr/0032-a-protected-event-is-published-only-by-its-authenticated-author.md)).

## Storage

SQLite in WAL mode. Event IDs and pubkeys stored as 32-byte binary blobs for space efficiency. Every event is parsed and validated on the way in, and its tags and content are indexed for filtering and full-text search; its JSON is serialised once at that point and stored alongside, and that stored text is what the relay returns, so an event is never re-serialised on a read.

Tested at two million events (a 5.6 GB database): author and kind lookups stay below a millisecond, and hashtag and full-text queries take a few hundred milliseconds. The explore statistics are the expensive part. There are 41 of them, refreshed one per tick on a ten-minute rotation, so a tick falls every 14.6 seconds; recomputing all 41 costs about 50 seconds of worker time, and the slowest of them, the totals refresh, takes about 17 seconds on its own. A refresh that overruns its tick does not queue behind itself, so on a store this size the rotation stretches beyond ten minutes rather than falling behind. Write concurrency is not a concern: SQLite has one writer, so every write is serialised through a single write worker, and `connection_limits.max_connections` (100 by default) bounds how many clients can be submitting at once.

Events carrying a NIP-40 `expiration` tag are deleted once it passes, by a sweep that runs every ten minutes; there is no setting to turn it off, and the rationale is recorded in [ADR-0028](docs/adr/0028-expired-events-are-swept-from-storage-not-filtered-on-read.md).

Fact tables (follows, mutes, relay preferences, zap receipts) are populated on event insert. Explore and analytics figures are never stored as running counters — they are recomputed from those base tables by scheduled refresh tasks and cached in `stat_results`, so a delete, ban or supersede is reflected at the next refresh rather than drifting. The rationale is recorded in [ADR-0012](docs/adr/0012-derived-stats-recompute-from-base-tables.md).

The database lives in `data/` and the caches in `var/`. Back up `data/`; you can delete `var/` at any time without losing anything. The relay writes no log file of its own, logging to standard output for its supervisor to keep. The sibling `hubstr-blossom` service uses the same layout.

## Stack

- PHP 8.4+ with Amphp (async WebSocket server)
- SQLite (WAL mode) — database work runs on dedicated worker processes, off the event loop
- Caddy for TLS termination (automatic HTTPS via Let's Encrypt)
- Built on the `innis/nostr-relay`, `innis/nostr-core`, and `innis/hubstr-core` packages ([github.com/johninnis/nostr-relay](https://github.com/johninnis/nostr-relay), [github.com/johninnis/nostr-core](https://github.com/johninnis/nostr-core), [github.com/johninnis/hubstr-core](https://github.com/johninnis/hubstr-core)). `hubstr-core` supplies the `Kernel` application entrypoint.

## Requirements

- PHP 8.4+ with `ext-pdo_sqlite`, `ext-pcntl`, `ext-zlib`, `ext-gmp`, `ext-intl`, `ext-mbstring`, `ext-ctype`, `ext-openssl` and `ext-sodium`
- `ext-ffi` with `libsecp256k1` for native signature verification. A deployment can run without it, on a pure-PHP fallback; a development install cannot, because the test suite exercises the native path and `ext-ffi` is a dev requirement
- [Caddy](https://caddyserver.com/) (or another reverse proxy) for TLS in a public deployment

## Install

```bash
git clone https://github.com/johninnis/hubstr-relay.git
cd hubstr-relay
composer install
cp config/relay.example.php config/relay.php
```

**Deploying a release:** check out the release tag *before* installing, so the relay reports the release version (for example `v0.1.0`) in its NIP-11 document and landing page rather than a branch ref:

```bash
git checkout v0.1.0
composer install --no-dev
```

The version is read from the git state at install time: a tag checkout reports that tag, while staying on `master` (or pulling past the tag) reports `dev-master@<sha>`.

## Configuration

Set at least `admin_pubkey` (the bootstrap admin/tenant pubkey, as 64 hex characters or an `npub`), `relay_url` and `database_path` in `config/relay.php`. Those three are required — the relay refuses to start without them, and `admin_pubkey` must parse as a public key in either form. `log_level` is optional and defaults to `info`. The relay writes its log to standard output only and keeps no log file: under the shipped systemd unit, read it with `journalctl`. A key the relay does not know, a leftover `log_path` included, stops it at start-up by name.

`config/relay.example.php` is the full reference. The other notable keys are:

| Key | Purpose |
|-----|---------|
| `host`, `port` | Listen address (default `127.0.0.1:8080`) |
| `connection_limits` | Transport capacity. `max_connections` caps concurrent WebSocket connections — it governs the socket, not what a connected client may ask for |
| `limits` | Per-connection policy, enforced per request and advertised in the NIP-11 `limitation` document: `max_subscriptions`, `max_filters`, `max_limit`, `max_content_length`. `max_limit` also bounds a NIP-45 `COUNT`: the relay stops counting there and marks the reply approximate. A tenant is exempt from `max_subscriptions` and `max_filters` only. `max_limit` clamps every filter whoever asks (see [ADR-0004](docs/adr/0004-nip42-is-a-tenant-only-scope-lift-offer-not-a-connection-gate.md)), and `max_content_length` is checked before the tenant bypass (see [ADR-0031](docs/adr/0031-the-size-limit-and-the-content-blacklist-are-checked-ahead-of-the-tenant-bypass.md)), so both bind a tenant too |
| `trusted_proxies` | Proxy IPs whose forwarded-for client address is trusted (set to your reverse proxy) |
| `name`, `description`, `contact`, `icon` | NIP-11 relay metadata defaults |

Set `HUBSTR_RELAY_CONFIG` to load a different config file instead of `config/relay.php` — the leak and stress harnesses use it to point the relay at a throwaway config:

```bash
HUBSTR_RELAY_CONFIG=/path/to/relay.php php bin/hubstr-relay.php
```

## Run

```bash
php bin/hubstr-relay.php
```

## Deployment

The relay listens on localhost. Use Caddy (or similar) as a reverse proxy for TLS termination. All traffic arrives on a single URL, routed by headers:

| Header | Route |
|--------|-------|
| `Accept: application/nostr+json` | NIP-11 relay info |
| `Content-Type: application/nostr+json+rpc` (POST) | NIP-86 management API |
| WebSocket `Upgrade` | Nostr protocol |
| Plain browser GET | Landing page (relay name, owner npub, version) |

See `resources/Caddyfile` for an example configuration.

### Running as a service

For a VPS deployment, run the relay under systemd so it survives logout and restarts on failure. An example unit is in `resources/hubstr-relay.service`.

```bash
sudo cp resources/hubstr-relay.service /etc/systemd/system/
# Edit User, WorkingDirectory, and ReadWritePaths to match your host
sudo systemctl daemon-reload
sudo systemctl enable --now hubstr-relay
sudo journalctl -u hubstr-relay -f
```

## CLI tools

```bash
# Import events from JSONL
cat events.jsonl | php bin/import.php

# Export events to JSONL (with optional filters)
php bin/export.php > backup.jsonl
php bin/export.php --kind=1 --author=<hex|npub> --tagged=<hex|npub> --since=<timestamp> --until=<timestamp>
```

`export.php` refuses an option it cannot parse — a kind that is not a number, a key that is neither hex nor an npub, a timestamp that is not an integer — naming the option on stderr and exiting with status 2, rather than exporting against a filter you did not ask for.

`--author` and `--tagged` select events the key wrote and events that `p`-tag it; given together they export the union of both, each event once, which is the whole of a key's presence on the relay. `--kind`, `--since` and `--until` narrow whichever selection applies.

## Development

```bash
composer test              # Full test suite + static analysis
composer test-unit         # Unit tests only
composer test-integration  # Integration tests only
composer test-coverage     # Coverage report
composer analyse           # PHPStan (level 9)
composer fix-style         # PHP-CS-Fixer
composer check-style       # Dry-run style check
composer rector            # Apply the PHP 8.4 modernisation rules
composer check-rector      # Dry-run Rector check
```

Two shell harnesses drive a real relay process over the network with [nak](https://github.com/fiatjaf/nak), against a throwaway config and database on a free port; the fixture they share lives in `tools/lib/relay-under-test.sh`:

```bash
tools/stress-test.sh         # Hundreds of tenant writes, thousands of guest REQs; reports throughput
tools/leak-test.sh           # Sustained connection churn; samples memory and file descriptors for leaks
```

Both take their sizes from environment variables named in the script headers.

## Architecture decisions

Design rationale — the deliberate choices that read like smells until you know why — lives in version-controlled records under [`docs/adr/`](docs/adr/).

## License

MIT
