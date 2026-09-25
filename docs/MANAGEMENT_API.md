# Hubstr Relay Management API

## Overview

Hubstr Relay exposes a management API using [NIP-86](https://github.com/nostr-protocol/nips/blob/master/86.md) (JSON-RPC over HTTP) authenticated with [NIP-98](https://github.com/nostr-protocol/nips/blob/master/98.md) (HTTP Auth).

All management requests are POST requests to the relay's root URL with `Content-Type: application/nostr+json+rpc`. Only tenant pubkeys (relay admins) can call these methods.

## Authentication

Every request must include an `Authorization` header containing a signed NIP-98 event.

### Building the Authorization header

1. Construct a kind 27235 event with these tags:
   - `["u", "<relay_url>"]` — the relay's URL in its `https://` form (the configured `wss://relay.example.com` is signed as `https://relay.example.com`). The comparison normalises first, so the scheme and host are matched case-insensitively, a default port may be written or left out, and a missing path counts as `/`
   - `["method", "POST"]`
   - `["payload", "<sha256_hex>"]` — SHA-256 hash of the request body

2. Set `created_at` to current unix timestamp (must be within 60 seconds)

3. Sign the event with a tenant's private key. Sign a fresh event for every request: the relay refuses an auth event whose id it has already accepted, so a header cannot be reused even inside the 60-second window

4. JSON-encode the signed event, base64-encode it, prefix with `Nostr `

```
Authorization: Nostr <base64(json(signed_event))>
```

### Example (pseudocode)

```typescript
const body = JSON.stringify({ method: "listallowedpubkeys" });
const payloadHash = sha256Hex(body);

const authEvent = {
  pubkey: tenantPubkey,
  created_at: Math.floor(Date.now() / 1000),
  kind: 27235,
  tags: [
    ["u", "https://relay.example.com"],
    ["method", "POST"],
    ["payload", payloadHash],
  ],
  content: "",
};

// sign the event, then:
const header = "Nostr " + btoa(JSON.stringify(signedAuthEvent));
```

## Request Format

```
POST / HTTP/1.1
Content-Type: application/nostr+json+rpc
Authorization: Nostr <base64_event>

{"method": "<method_name>", "params": [<param1>, <param2>, ...]}
```

## Response Format

Success:
```json
{"result": <value>}
```

Error:
```json
{"error": "<message>"}
```

Every error carries an HTTP status as well as the message:

| Status | When |
|--------|------|
| `400` | The request is malformed or a parameter does not parse: invalid JSON, a body that is not a `{"method", "params"}` envelope, an unknown method, an invalid pubkey or IP address, a missing required string, a banned word shorter than 3 characters, relay metadata that breaks its rules, rate limits that are not positive integers, a guest policy with an unparseable tag rule |
| `401` | The `Authorization` header is missing, or the NIP-98 event in it does not validate: a bad signature, a timestamp outside the 60-second window, a `u`, `method` or `payload` tag that does not match the request, or an auth event whose id the relay has already accepted (a replay). The message is `auth-required: <reason>` |
| `403` | The NIP-98 event is valid but its signer is not a tenant: `Pubkey is not a relay tenant` |
| `409` | The request is well formed but the relay's state refuses it: `Cannot remove the last tenant`, `Cannot blacklist an active tenant pubkey` |
| `500` | The method failed unexpectedly: `internal server error`. The cause is in the relay's log, never in the response |

## Methods

### Tenant Management

#### `allowpubkey`
Add a pubkey as a tenant (full read/write access).

- **Params**: `[pubkey_hex]`

```json
{"method": "allowpubkey", "params": ["aabbcc..."]}
```
```json
{"result": true}
```

#### `unallowpubkey`
Remove a tenant. Cannot remove the last tenant.

- **Params**: `[pubkey_hex]`

```json
{"method": "unallowpubkey", "params": ["aabbcc..."]}
```
```json
{"result": true}
```

The last tenant cannot be removed — a relay with none has nobody left who can call this API. The refusal is a `409`:
```json
{"error": "Cannot remove the last tenant"}
```

Removing a pubkey that is not a tenant is a no-op and returns `true`.

#### `listallowedpubkeys`
List all tenant pubkeys, as NIP-86 records. This relay records no reason for a tenancy, so the optional `reason` field is omitted.

```json
{"method": "listallowedpubkeys"}
```
```json
{
  "result": [
    {"pubkey": "12405b5e4e499939ec32ea80e25707d76ca039931ad4bb6bf2bae0719335d5bf"},
    {"pubkey": "a3e4f5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3"}
  ]
}
```

### Pubkey Blacklist

#### `banpubkey`
Block a pubkey. Existing events from this pubkey are deleted asynchronously.

- **Params**: `[pubkey_hex]`

```json
{"method": "banpubkey", "params": ["aabbcc..."]}
```
```json
{"result": true}
```

A tenant cannot be banned; remove it as a tenant first. The refusal is a `409`:
```json
{"error": "Cannot blacklist an active tenant pubkey"}
```

#### `unbanpubkey`
Unblock a pubkey.

- **Params**: `[pubkey_hex]`

```json
{"method": "unbanpubkey", "params": ["aabbcc..."]}
```
```json
{"result": true}
```

#### `listbannedpubkeys`
List blocked pubkeys, as NIP-86 records. This relay records no reason for a pubkey ban, so the optional `reason` field is omitted.

```json
{"method": "listbannedpubkeys"}
```
```json
{
  "result": [
    {"pubkey": "d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4"}
  ]
}
```

### IP Blocking

#### `blockip`
Block an IP address. The reason is optional, is trimmed, and may be at most 2048 characters; a longer one is refused with a `400`.

- **Params**: `[ip, reason?]`

```json
{"method": "blockip", "params": ["1.2.3.4", "spam"]}
```
```json
{"result": true}
```

#### `unblockip`
Unblock an IP address.

- **Params**: `[ip]`

```json
{"method": "unblockip", "params": ["1.2.3.4"]}
```
```json
{"result": true}
```

#### `listblockedips`
List blocked IPs.

```json
{"method": "listblockedips"}
```
```json
{
  "result": [
    {"ip": "1.2.3.4", "reason": "spam"},
    {"ip": "5.6.7.8", "reason": ""}
  ]
}
```

### Relay Metadata

These override the config file values. Changes are reflected immediately in NIP-11 responses and, for the name, on the landing and error pages.

Each value is trimmed. A name or description longer than 2048 characters is refused, and an icon must be an absolute `http://` or `https://` URL of at most 2048 characters; either refusal is a `400`. Sending an empty string clears the override, so the config file value applies again.

#### `changerelayname`
- **Params**: `[name]`

```json
{"method": "changerelayname", "params": ["My Personal Relay"]}
```
```json
{"result": true}
```

#### `changerelaydescription`
- **Params**: `[description]`

```json
{"method": "changerelaydescription", "params": ["A relay for my friends"]}
```
```json
{"result": true}
```

#### `changerelayicon`
- **Params**: `[icon_url]`

```json
{"method": "changerelayicon", "params": ["https://example.com/icon.png"]}
```
```json
{"result": true}
```

### Word Blacklist

#### `banword`
Block a word. Events containing this word (case-insensitive substring) will be rejected. Existing matching events are deleted asynchronously by the same test, so the ban removes exactly what it would refuse.

The word is trimmed and lowercased, and must be at least 3 characters afterwards. A shorter fragment appears in ordinary prose often enough that banning it would delete most of the store, and the deletion cannot be undone, so it is refused with `400` (see [ADR-0030](adr/0030-a-content-ban-purges-through-the-filter-that-refused-the-write.md)).

Deleting is the expensive path. A substring test cannot use an index, so the purge reads every stored event once per chunk and the write worker accepts nothing while it does. On a two-million-event store one pass takes about six seconds. The ban itself takes effect immediately; only the removal of what is already stored is slow.

- **Params**: `[word]`

```json
{"method": "banword", "params": ["spam"]}
```
```json
{"result": true}
```

#### `unbanword`
Unblock a word. The same 3-character rule applies, so a word that could not be banned cannot be unbanned either.

- **Params**: `[word]`

```json
{"method": "unbanword", "params": ["spam"]}
```
```json
{"result": true}
```

#### `listbannedwords`

```json
{"method": "listbannedwords"}
```
```json
{
  "result": ["spam", "scam", "airdrop"]
}
```

### Hashtag Blacklist

#### `banhashtag`
Block a hashtag. Events with this hashtag in their `t` tags will be rejected. Existing matching events are deleted asynchronously.

The hashtag is lowercased, so the match is case-insensitive and `listbannedhashtags` reports it in lowercase. An empty value is refused with a `400`.

- **Params**: `[hashtag]` (without #)

```json
{"method": "banhashtag", "params": ["nsfw"]}
```
```json
{"result": true}
```

#### `unbanhashtag`
- **Params**: `[hashtag]`

```json
{"method": "unbanhashtag", "params": ["nsfw"]}
```
```json
{"result": true}
```

#### `listbannedhashtags`

```json
{"method": "listbannedhashtags"}
```
```json
{
  "result": ["nsfw", "crypto"]
}
```

### Guest Policy

Controls what unauthenticated users can read and write.

#### `getguestpolicy`

```json
{"method": "getguestpolicy"}
```
```json
{
  "result": {
    "read": {
      "kinds": [0, 1, 3, 6, 7, 16, 20, 21, 22, 9802, 1111, 9321, 9735, 10000, 10001, 10002, 10050, 10003, 10015, 10030, 10063, 30003, 30004, 30023, 30030, 24133],
      "global_kinds": [24133],
      "from_tenants_only": true
    },
    "write": {
      "kinds": [1, 7, 1111, 9321, 9735, 1059, 24133],
      "tagged_to_tenant": true,
      "tag_prefixes": []
    }
  }
}
```

The response above is the built-in default, which applies until a policy is first saved.

`read.global_kinds` lists kinds a guest may read regardless of author, bypassing `from_tenants_only` (see [ADR-0013](adr/0013-global-read-kinds-open-nip46-connect-to-unauthenticated-signers.md)).

`read.kinds` and `read.global_kinds` together are the whole readable set: a guest may read a kind only if it appears in one of them. An empty `read.kinds` leaves only the global kinds readable, and emptying both means guests read nothing. Kinds are integers; an entry that is not an integer is dropped, which only ever narrows the set.

`write.tag_prefixes` is a list of rules, each naming a tag and the value prefixes it will accept. A guest event satisfies the list when **any one** rule matches. An empty list -- the default -- means no such requirement. The match is exact and case-sensitive, and it applies to writes only; there is no read equivalent, because a prefix cannot be expressed as a NIP-01 filter (see [ADR-0018](adr/0018-guest-writes-can-be-admitted-by-tag-value-prefix.md) and [ADR-0019](adr/0019-guest-write-provenance-is-a-set-of-alternatives.md)).

Whether the relay advertises NIP-17 in its relay-information document follows this policy: 17 is listed while kind 1059 is a writable kind with `tagged_to_tenant` on and is in neither `read.kinds` nor `read.global_kinds`.

`tagged_to_tenant` and `tag_prefixes` are **alternatives**, not both-required: an event satisfies the policy by meeting whichever of the configured checks it can. A relay with both on takes gift wraps (which tag a tenant and name no page) and page comments (which name a page and tag no tenant) alike. The `kinds` list is not an alternative -- it is a precondition every guest write must pass.

#### `setguestpolicy`
- **Params**: `[policy_object]` -- same structure as `getguestpolicy` result

The submitted object **replaces the whole policy**. A key that is left out does not keep its current value: it takes the built-in default shown under `getguestpolicy`. To change one setting, read the policy, edit it, and send all of it back.

```json
{"method": "setguestpolicy", "params": [{"read": {"kinds": [1, 7], "global_kinds": [], "from_tenants_only": false}, "write": {"kinds": [7], "tagged_to_tenant": false, "tag_prefixes": []}}]}
```
```json
{"result": true}
```

Confining guests to comments on one site's pages, where the tag is NIP-22's uppercase `I` root scope and the value is the page URL:

```json
{"method": "setguestpolicy", "params": [{"read": {"kinds": [1111], "from_tenants_only": false}, "write": {"kinds": [1111], "tagged_to_tenant": false, "tag_prefixes": [{"tag": "I", "prefixes": ["https://www.example.com/"]}, {"tag": "i", "prefixes": ["https://www.example.com/"]}]}}]}
```

Two rules, because a comment names its page in NIP-22's uppercase root tag while a note or a kind 17 reaction names it in NIP-73's lowercase one. Either satisfies the list.

A rule that names no tag, or lists no prefix, or lists an empty one, refuses the whole submission with `400` and the stored policy is left unchanged. It is not defaulted away, because silently storing no requirement would leave the relay admitting everything while the operator believed it was constrained. Send `"tag_prefixes": []` or `null` to remove all rules.

### Rate Limits

#### `getratelimits`

```json
{"method": "getratelimits"}
```
```json
{
  "result": {
    "events_per_minute": 240,
    "subscriptions_per_minute": 60
  }
}
```

#### `setratelimits`
- **Params**: `[limits_object]` -- same structure as `getratelimits` result

Unlike `setguestpolicy`, this is a patch: a key that is left out keeps its current value. Both values must be positive integers, otherwise the request is refused with a `400` and nothing changes.

```json
{"method": "setratelimits", "params": [{"events_per_minute": 200, "subscriptions_per_minute": 50}]}
```
```json
{"result": true}
```

### Monitoring

#### `getstats`
Event store statistics and fact table counts. The figures are recomputed by a scheduled refresh and served from a cache, so they can trail the live database by up to ten minutes.

```json
{"method": "getstats"}
```
```json
{
  "result": {
    "events": 393514,
    "tags": 5434686,
    "follows": 12340,
    "mutes": 456,
    "relays": 8901,
    "zaps": 2345,
    "known_pubkeys": 6789,
    "events_by_kind": [
      {"kind": 1, "count": 228530},
      {"kind": 7, "count": 76146},
      {"kind": 0, "count": 34521},
      {"kind": 6, "count": 21003}
    ],
    "events_by_tenant": [
      {"pubkey": "fc63ad3faeb6498ce26540ee3608937a77981d55a5b3eb1d443ce423f01c8b3e", "count": 69},
      {"pubkey": "12405b5e4e499939ec32ea80e25707d76ca039931ad4bb6bf2bae0719335d5bf", "count": 0}
    ]
  }
}
```

#### `listconnections`
List all currently connected WebSocket clients and their subscriptions.

```json
{"method": "listconnections"}
```
```json
{
  "result": [
    {
      "id": "a1b2c3d4e5f6...",
      "ip": "192.168.1.42",
      "user_agent": "Damus/1.5",
      "connected_at": 1712400000,
      "events_received": 42,
      "events_accepted": 40,
      "events_sent": 310,
      "subscriptions": [
        {
          "id": "timeline:main",
          "state": "live",
          "filters": [
            {"kinds": [1, 6], "authors": ["12405b5e..."], "limit": 50}
          ]
        },
        {
          "id": "notifications",
          "state": "live",
          "filters": [
            {"#p": ["12405b5e..."], "since": 1712300000}
          ]
        }
      ]
    },
    {
      "id": "f7e8d9c0b1a2...",
      "ip": "10.0.0.5",
      "user_agent": "Amethyst/0.88",
      "connected_at": 1712401200,
      "events_received": 0,
      "events_accepted": 0,
      "events_sent": 0,
      "subscriptions": []
    }
  ]
}
```

#### `getconnection`
A single connected client by its connection id, in the same shape as a `listconnections` entry. Returns `null` for an unknown id.

- **Params**: `[connection_id]`

```json
{"method": "getconnection", "params": ["a1b2c3d4e5f6..."]}
```
```json
{
  "result": {
    "id": "a1b2c3d4e5f6...",
    "ip": "192.168.1.42",
    "user_agent": "Damus/1.5",
    "connected_at": 1712400000,
    "events_received": 42,
    "events_accepted": 40,
    "events_sent": 310,
    "subscriptions": []
  }
}
```

#### `listsubscriptions`
Flat list of all active subscriptions across all clients.

```json
{"method": "listsubscriptions"}
```
```json
{
  "result": [
    {
      "client_id": "a1b2c3d4e5f6...",
      "client_ip": "192.168.1.42",
      "subscription_id": "timeline:main",
      "state": "live",
      "filters": [
        {"kinds": [1, 6], "authors": ["12405b5e..."], "limit": 50}
      ]
    },
    {
      "client_id": "a1b2c3d4e5f6...",
      "client_ip": "192.168.1.42",
      "subscription_id": "notifications",
      "state": "active",
      "filters": [
        {"#p": ["12405b5e..."], "since": 1712300000}
      ]
    }
  ]
}
```

### Explore

#### `explore`
Aggregated leaderboard data across 10 categories, filtered by time period: trending content, top profiles, and engagement metrics. Every leaderboard is recomputed from the base tables by a scheduled refresh — one category and period at a time, the whole set about every ten minutes — and served from a cache, so a result can trail the live database by up to that long.

- **Params**: positional `[period, limit]` (both optional)
- **Period**: `24h`, `7d`, `30d`, `all` (default: `all`)
- **Limit**: 1-100 (default: 20, capped at 10 for `most_zapped_notes`)

Each entry carries its identifying field — `hashtag`, `pubkey`, or `event_id` depending on the category — plus a `count`.

```json
{"method": "explore", "params": ["7d", 10]}
```
```json
{
  "result": {
    "period": "7d",
    "data": {
      "trending_hashtags": [
        {"hashtag": "nostr", "count": 58},
        {"hashtag": "bitcoin", "count": 42}
      ],
      "most_followed": [
        {"pubkey": "82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2", "count": 8}
      ],
      "most_muted": [
        {"pubkey": "d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4", "count": 3}
      ],
      "most_zapped": [
        {"pubkey": "fe7f6bc6f7338b76bbf80db402ade65953e20b2f23e66e898204b63cc42539a3", "count": 150}
      ],
      "most_zapped_by_sats": [
        {"pubkey": "fe7f6bc6f7338b76bbf80db402ade65953e20b2f23e66e898204b63cc42539a3", "count": 7124537}
      ],
      "top_zappers": [
        {"pubkey": "8fb140b4e8ddef97ce4b821d247278a1a4353362623f64021484b372f948000c", "count": 95}
      ],
      "top_zappers_by_sats": [
        {"pubkey": "8fb140b4e8ddef97ce4b821d247278a1a4353362623f64021484b372f948000c", "count": 255076}
      ],
      "most_reacted_to": [
        {"pubkey": "6e468422dfb74a5738702a8823b9b28168abab8655faacb6853cd0ee15deee93", "count": 16}
      ],
      "most_reposted": [
        {"pubkey": "c48e29f04b482cc01ca1f9ef8c86ef8318c059e0e9353235162f080f26e14c11", "count": 56}
      ],
      "most_zapped_notes": [
        {"event_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1", "count": 815}
      ]
    }
  }
}
```

**Categories:**

| Category | Identifying field | `count` |
|----------|-------------------|---------|
| `trending_hashtags` | `hashtag` (lowercase) | occurrences |
| `most_followed` | `pubkey` hex | follower count |
| `most_muted` | `pubkey` hex | mute count |
| `most_zapped` | `pubkey` hex | zap receipt count |
| `most_zapped_by_sats` | `pubkey` hex | total sats received |
| `top_zappers` | `pubkey` hex | zaps sent count |
| `top_zappers_by_sats` | `pubkey` hex | total sats sent |
| `most_reacted_to` | `pubkey` hex | reaction count |
| `most_reposted` | `pubkey` hex | repost count |
| `most_zapped_notes` | `event_id` hex | zap count |

**Time period behaviour:**
- `24h`, `7d`, `30d` — only counts events created within the window
- `all` — no time filter
- `most_followed` and `most_muted` count rows of the follow and mute fact tables, which hold one row per follower and followed pubkey (or muter and muted pubkey). With a time window, a row counts only if the follow or mute list it came from was itself published within the window

#### `getwotscore`
Web-of-Trust score of a target pubkey as seen from the authenticated tenant: whether the tenant follows the target directly, and how many of the tenant's follows follow the target.

- **Params**: `[pubkey_hex]`

```json
{"method": "getwotscore", "params": ["82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2"]}
```
```json
{
  "result": {
    "pubkey": "82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2",
    "followed": true,
    "mutual_follows": 4,
    "distance": 1
  }
}
```

`distance` is `1` for a direct follow, `2` when reachable only through mutual follows, and `null` when unreachable.

### Discovery

#### `supportedmethods`
List all available RPC method names.

```json
{"method": "supportedmethods"}
```
```json
{
  "result": [
    "allowpubkey", "banhashtag", "banpubkey", "banword",
    "blockip", "changerelaydescription", "changerelayicon", "changerelayname",
    "explore", "getconnection", "getguestpolicy", "getratelimits",
    "getstats", "getwotscore", "listallowedpubkeys", "listbannedhashtags",
    "listbannedpubkeys", "listbannedwords", "listblockedips", "listconnections",
    "listsubscriptions", "setguestpolicy", "setratelimits", "supportedmethods",
    "unallowpubkey", "unbanhashtag", "unbanpubkey", "unbanword",
    "unblockip"
  ]
}
```

## All Methods Summary

| Method | Params | Description |
|--------|--------|-------------|
| `allowpubkey` | `[pubkey_hex]` | Add tenant |
| `unallowpubkey` | `[pubkey_hex]` | Remove tenant |
| `listallowedpubkeys` | none | List tenant pubkeys |
| `banpubkey` | `[pubkey_hex]` | Blacklist pubkey and delete events |
| `unbanpubkey` | `[pubkey_hex]` | Remove pubkey from blacklist |
| `listbannedpubkeys` | none | List blacklisted pubkeys |
| `blockip` | `[ip, reason?]` | Block IP address |
| `unblockip` | `[ip]` | Unblock IP address |
| `listblockedips` | none | List blocked IPs |
| `changerelayname` | `[name]` | Override relay name |
| `changerelaydescription` | `[description]` | Override relay description |
| `changerelayicon` | `[icon_url]` | Override relay icon |
| `banword` | `[word]` | Blacklist word and delete matches |
| `unbanword` | `[word]` | Remove word from blacklist |
| `listbannedwords` | none | List blacklisted words |
| `banhashtag` | `[hashtag]` | Blacklist hashtag and delete matches |
| `unbanhashtag` | `[hashtag]` | Remove hashtag from blacklist |
| `listbannedhashtags` | none | List blacklisted hashtags |
| `getguestpolicy` | none | Get guest read/write policy |
| `setguestpolicy` | `[policy]` | Set guest read/write policy |
| `getratelimits` | none | Get rate limit settings |
| `setratelimits` | `[limits]` | Set rate limit settings |
| `getstats` | none | Event store statistics |
| `listconnections` | none | Connected clients and subscriptions |
| `getconnection` | `[connection_id]` | A single connected client, or `null` |
| `listsubscriptions` | none | All active subscriptions (flat) |
| `explore` | `[period, limit]` | Aggregated leaderboard data (10 categories) |
| `getwotscore` | `[pubkey_hex]` | Web-of-Trust score for a pubkey |
| `supportedmethods` | none | List available methods |
