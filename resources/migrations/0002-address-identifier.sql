-- Files every replaceable and addressable event under the identifier nostr-core's
-- EventCoordinate::tryFromEvent() gives it, so replacement and NIP-09 coordinate deletion
-- match an address on one column. The column is NULL for every other kind.
--
-- Rows already stored are backfilled here in SQL, replicating the library's reading
-- (nostr-adrs ADR-0014, innis/nostr-core ADR-0092): a replaceable kind is addressed with
-- the empty identifier; an addressable kind with no d tag carrying a value is addressed
-- with the empty identifier, d tags that agree name that value, and d tags that disagree
-- name nothing, so the column stays NULL.

ALTER TABLE events ADD COLUMN address_identifier TEXT;

UPDATE events SET address_identifier = ''
WHERE kind IN (0, 3) OR (kind >= 10000 AND kind < 20000);

UPDATE events SET address_identifier = (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN ''
        WHEN COUNT(DISTINCT json_extract(dtag.value, '$[1]')) = 1
            THEN MIN(json_extract(dtag.value, '$[1]'))
    END
    FROM json_each(events.raw_event, '$.tags') AS dtag
    WHERE json_extract(dtag.value, '$[0]') = 'd'
      AND json_extract(dtag.value, '$[1]') IS NOT NULL
)
WHERE kind >= 30000 AND kind < 40000;

CREATE INDEX IF NOT EXISTS idx_events_address ON events (pubkey, kind, address_identifier)
    WHERE address_identifier IS NOT NULL;
