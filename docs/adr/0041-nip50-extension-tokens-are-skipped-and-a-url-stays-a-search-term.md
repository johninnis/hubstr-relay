# 41. NIP-50 extension tokens are skipped, and a URL stays a search term

## Status

Accepted

## Context

NIP-50 lets a `search` string carry `key:value` extension tokens — `include:spam`, `domain:`, `language:`, `sentiment:`, `nsfw:` — and says a relay ignores the extensions it does not support. This relay supports none of them: it has no spam filtering to turn off, no NIP-05 index, and no language, sentiment or NSFW classifier. Left alone, the FTS query builder would quote every token and search for the literal text `include:spam`, so a client using a spec-legal extension would silently get nothing useful back.

Dropping every token that contains a colon is the obvious implementation and the wrong one: a search for `https://example.com` would lose its only term, and a time like `10:30` would vanish too. NIP-50's own definition is narrower — "two words separated by colon" — so the boundary has to be drawn where a word-colon-word ends and a URL or a timestamp begins.

## Decision

`FtsQuery` drops a token only when it matches a word, one colon, and a value holding no further colon or whitespace: letters, digits or underscore in the key, anything but a colon or space in the value. Every NIP-50 extension shape matches that; `https://…` does not (its value holds a second colon), and neither does `10:30` (its key is not a word).

A search whose tokens are all extensions is treated as carrying no search constraint at all, because ignoring the extensions is what the spec asks for: the filter's other fields decide the result. A search that is empty or whitespace still matches nothing.

No extension is honoured, not even `domain:`. Supporting one means maintaining the index it filters by, and none is worth that here.

## Consequences

- A client sending spec-legal extensions gets the same events as if it had sent the plain terms, which is the behaviour NIP-50 tells it to expect from a relay that does not support the extension.
- A URL or a timestamp in a query is searched literally, as users mean it.
- The boundary is pinned by `EventStorageTest`: an extension token is skipped, an extension-only search imposes no constraint, a URL stays a term, and whitespace still matches nothing.
- Do not "simplify" the pattern to drop every token containing a colon — that eats URLs, the mistake the pattern's shape exists to avoid.
- If the relay ever does support an extension, that extension is parsed out before `FtsQuery` and honoured; the skip rule here covers only unsupported ones.
