-- Hubstr Relay Schema
-- Engine: SQLite
-- Design: event storage with a tag index and FTS5 search, fact tables for explore,
-- policy tables managed over NIP-86, and a cache of computed stat results.

-- =============================================================================
-- Core event storage
-- =============================================================================

CREATE TABLE IF NOT EXISTS events (
    event_id BLOB PRIMARY KEY,          -- 32 bytes, raw binary
    pubkey BLOB NOT NULL,               -- 32 bytes, raw binary
    kind INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    content TEXT NOT NULL,
    raw_event BLOB NOT NULL             -- original JSON stored verbatim
);

CREATE INDEX IF NOT EXISTS idx_events_pubkey_kind_created ON events (pubkey, kind, created_at);
CREATE INDEX IF NOT EXISTS idx_events_kind_created ON events (kind, created_at);
CREATE INDEX IF NOT EXISTS idx_events_created ON events (created_at);

-- FTS5 virtual table for NIP-50 search
CREATE VIRTUAL TABLE IF NOT EXISTS events_fts USING fts5(
    content,
    content='events',
    content_rowid='rowid'
);

-- Triggers to keep FTS in sync
CREATE TRIGGER IF NOT EXISTS events_ai AFTER INSERT ON events BEGIN
    INSERT INTO events_fts(rowid, content) VALUES (new.rowid, new.content);
END;

CREATE TRIGGER IF NOT EXISTS events_ad AFTER DELETE ON events BEGIN
    INSERT INTO events_fts(events_fts, rowid, content) VALUES ('delete', old.rowid, old.content);
END;

CREATE TRIGGER IF NOT EXISTS events_au AFTER UPDATE ON events BEGIN
    INSERT INTO events_fts(events_fts, rowid, content) VALUES ('delete', old.rowid, old.content);
    INSERT INTO events_fts(rowid, content) VALUES (new.rowid, new.content);
END;

-- =============================================================================
-- Tag index for NIP-01 filter queries
-- =============================================================================

CREATE TABLE IF NOT EXISTS event_tags (
    event_id BLOB NOT NULL,
    tag_name TEXT NOT NULL,
    tag_value TEXT NOT NULL,

    PRIMARY KEY (tag_name, tag_value, event_id),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS idx_tags_event_name_value ON event_tags (event_id, tag_name, tag_value);

-- =============================================================================
-- Denormalized tables for explore/stats queries
-- Derived from replaceable events stored in the events table.
-- Updated on insert when relevant event kinds arrive.
-- =============================================================================

-- Derived from kind 3 (contact list) — replaceable per pubkey
CREATE TABLE IF NOT EXISTS profile_follows (
    follower_pubkey BLOB NOT NULL,      -- 32 bytes, who follows
    followed_pubkey BLOB NOT NULL,      -- 32 bytes, who is followed

    PRIMARY KEY (follower_pubkey, followed_pubkey)
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS idx_follows_followed ON profile_follows (followed_pubkey);

-- Derived from kind 10000 (mute list) — replaceable per pubkey
CREATE TABLE IF NOT EXISTS profile_mutes (
    muter_pubkey BLOB NOT NULL,         -- 32 bytes, who mutes
    muted_pubkey BLOB NOT NULL,         -- 32 bytes, who is muted

    PRIMARY KEY (muter_pubkey, muted_pubkey)
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS idx_mutes_muted ON profile_mutes (muted_pubkey);

-- Derived from kind 10002 (relay list metadata) — replaceable per pubkey
CREATE TABLE IF NOT EXISTS profile_relays (
    pubkey BLOB NOT NULL,               -- 32 bytes
    relay_url TEXT NOT NULL,
    marker TEXT NOT NULL DEFAULT 'both', -- read, write, both

    PRIMARY KEY (pubkey, relay_url)
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS idx_relays_pubkey ON profile_relays (pubkey);

-- Derived from kind 9735 (zap receipt) — one row per zap event
CREATE TABLE IF NOT EXISTS zap_receipts (
    event_id BLOB PRIMARY KEY,          -- 32 bytes, references events.event_id
    sender_pubkey BLOB,                 -- 32 bytes, nullable (anonymous zaps)
    recipient_pubkey BLOB NOT NULL,     -- 32 bytes
    amount_msats INTEGER NOT NULL DEFAULT 0,

    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_zaps_sender ON zap_receipts (sender_pubkey);
CREATE INDEX IF NOT EXISTS idx_zaps_recipient ON zap_receipts (recipient_pubkey);

-- =============================================================================
-- Policy and configuration (managed via HTTP API)
-- =============================================================================

-- Tenant pubkeys with full read/write access
CREATE TABLE IF NOT EXISTS tenants (
    pubkey BLOB PRIMARY KEY,            -- 32 bytes
    created_at INTEGER NOT NULL
);

-- Content blacklist: words, pubkeys, hashtags.
-- The value column is polymorphic, so pubkeys are stored here as lowercase hex
-- text (not raw 32-byte BLOB as in the events/tenants tables).
CREATE TABLE IF NOT EXISTS blacklist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,                  -- 'word', 'pubkey', 'hashtag'
    value TEXT NOT NULL,
    created_at INTEGER NOT NULL,

    UNIQUE (type, value)
);

-- Blocked IP addresses
CREATE TABLE IF NOT EXISTS blocked_ips (
    ip TEXT PRIMARY KEY,
    reason TEXT,
    created_at INTEGER NOT NULL
);

-- Key-value settings (guest policy, rate limits, relay metadata overrides, etc.)
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,                 -- JSON for complex values
    updated_at INTEGER NOT NULL
);

-- =============================================================================
-- Cached stat results (see docs/adr/0012: derived stats are recomputed from
-- the base tables above, never incrementally maintained)
-- =============================================================================

CREATE TABLE IF NOT EXISTS stat_results (
    stat_name TEXT NOT NULL,
    period TEXT NOT NULL,
    computed_at INTEGER NOT NULL,
    payload BLOB NOT NULL,            -- JSON array of {identifier, count}
    PRIMARY KEY (stat_name, period)
);
