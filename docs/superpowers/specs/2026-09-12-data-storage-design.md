# Data Storage & Recommendation Design

**Date:** 2026-09-12
**Status:** Approved. Catalog crawl implemented 2026-09-18 on Laravel (see docs/internal_documentation.md,
"Catalog crawl"), with these changes: tags and review counts come from SteamSpy (Steam fallback),
embeddings are postponed, `tags` is json rather than `TEXT[]`, and crawls are scheduled every minute.

## Problem

Steam OpenID login and `GetOwnedGames` already work, but nothing is persisted. We need
to decide where user data, the game catalog, and recommendations live, and how a
recommendation is actually computed.

## Decisions

| Question | Decision |
|---|---|
| Catalog source | Static dataset import to bootstrap; incremental Steam crawl for production |
| Recommendation signal | Tag overlap **and** content embeddings, blended |
| "Lesser known" | `review_count <= 500` (config-driven) **and** `positive_ratio >= 0.75` |
| User model | Persistent account keyed by `steam_id`, library snapshot stored |
| Refresh cadence | Lazily on login when the snapshot is older than 7 days |
| Storage | One PostgreSQL instance with the `pgvector` extension |

## Architecture

Three Spring Modulith modules over a single database:

- **`catalog`** — games, tags, embeddings, crawl state.
- **`library`** — users, owned-game snapshots, dismissals.
- **`recommendation`** — scoring; reads the other two through their exported ports only.

`compose.yaml` currently runs `postgres:latest`, which does not ship `pgvector`. It must
change to an image that includes the extension (e.g. `pgvector/pgvector:pg17`), and the
first migration runs `CREATE EXTENSION IF NOT EXISTS vector`.

### Why one Postgres rather than a dedicated vector DB

The catalog is ~200k rows. An HNSW index at 384 dimensions is roughly
`rows x dims x 4 bytes` plus overhead — a few hundred MB, comfortable on a small
instance. A separate vector store would add a second service, dual writes that can
drift, and would make filtered search ("similar AND <=500 reviews AND not owned")
impossible in a single query. It solves a scale problem this project will not reach.

## Schema

### catalog

```sql
CREATE TABLE games (
    app_id            INTEGER PRIMARY KEY,
    name              TEXT NOT NULL,
    short_description TEXT,
    release_date      DATE,
    developer         TEXT,
    publisher         TEXT,
    price_cents       INTEGER,
    review_count      INTEGER,
    positive_ratio    NUMERIC(4,3),
    tags              TEXT[] NOT NULL DEFAULT '{}',
    embedding         VECTOR(384),
    raw_payload       JSONB,
    last_fetched_at   TIMESTAMPTZ,
    fetch_attempts    SMALLINT NOT NULL DEFAULT 0,
    last_fetch_error  TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ON games USING GIN (tags);
CREATE INDEX ON games USING HNSW (embedding vector_cosine_ops);
CREATE INDEX ON games (review_count) WHERE review_count <= 500;
CREATE INDEX ON games (last_fetched_at NULLS FIRST, fetch_attempts);

CREATE TABLE crawl_state (
    crawl_type          TEXT PRIMARY KEY,   -- 'discovery' | 'details_refresh' | 'embedding'
    last_app_id         INTEGER,
    last_modified_since TIMESTAMPTZ,
    last_run_at         TIMESTAMPTZ,
    last_completed_at   TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Notes:

- **Failures are columns, not a table.** A failure is a fact about a game, and only the
  current state matters. Three columns answer "should I stop retrying?" with no join
  and nothing to garbage-collect.
- **`tags TEXT[]` rather than a join table.** Array overlap via GIN is fast and the code
  is trivial. Ceiling: individual tags cannot be weighted and tag IDF is clunky to
  compute. Upgrade to a `game_tags` join table if recommendation quality demands it.
- **`raw_payload`** keeps the `appdetails` response verbatim so a field we failed to
  normalize can be backfilled without re-crawling for days.
- **No `is_indie` flag.** Steam's Indie genre is self-reported and over-applied; the
  review filters select for "lesser known and good" far better. The tag remains in `tags`.
- **`embedding` is nullable** so a game can exist before it is embedded; the embedding
  job's work queue is simply `WHERE embedding IS NULL`. Dimension is fixed by the model
  choice — changing it means re-embedding the whole catalog. Build the HNSW index
  *after* the initial bulk import.

### library

```sql
CREATE TABLE users (
    steam_id          TEXT PRIMARY KEY,
    persona_name      TEXT NOT NULL,
    avatar_url        TEXT,
    country_code      TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_login_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    library_synced_at TIMESTAMPTZ,
    library_private   BOOLEAN NOT NULL DEFAULT false
);

CREATE TABLE owned_games (
    steam_id            TEXT NOT NULL REFERENCES users ON DELETE CASCADE,
    app_id              INTEGER NOT NULL,   -- deliberately NOT an FK to games
    playtime_minutes    INTEGER NOT NULL,
    playtime_2w_minutes INTEGER,
    first_seen_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (steam_id, app_id)
);

CREATE INDEX ON owned_games (app_id);

CREATE TABLE dismissed_games (
    steam_id     TEXT NOT NULL REFERENCES users ON DELETE CASCADE,
    app_id       INTEGER NOT NULL,
    dismissed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (steam_id, app_id)
);
```

Notes:

- **`owned_games.app_id` has no foreign key on purpose.** Users own games the catalog
  has not discovered yet. An FK would fail the sync or silently drop rows. Do not "fix"
  this by adding the constraint.
- **Indexes.** `PRIMARY KEY (steam_id, app_id)` already indexes `steam_id` as the leading
  column, so a separate `(steam_id)` index would be redundant and paid for on every
  write. `app_id` is the trailing column and cannot use the PK, hence its own index
  (also the reverse lookup collaborative filtering would need later). `dismissed_games`
  needs no extra index.
- **`library_private`** distinguishes a private profile from an empty library; Steam
  returns an empty list for both. Without it the user sees a blank page with no
  explanation.

### recommendation

```sql
CREATE TABLE recommendations (
    steam_id     TEXT NOT NULL REFERENCES users ON DELETE CASCADE,
    app_id       INTEGER NOT NULL,
    score        NUMERIC(5,4) NOT NULL,
    rank         SMALLINT NOT NULL,
    reason       JSONB,
    generated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (steam_id, app_id)
);

CREATE INDEX ON recommendations (steam_id, rank);
```

`reason` stores matched tags and the vector score so the UI can explain *why* a game was
suggested. Rows carry no history worth keeping, so regeneration is delete-all-then-insert
for that user — unlike `owned_games`.

## Crawling

Three crawl types, each resumable because progress lives in the tables, not in the job.

**`discovery`** — walks `IStoreService/GetAppList/v1` using `last_app_id` as a cursor
(`max_results`, `have_more_results`) and `if_modified_since` as a watermark, filtering to
games only. Inserts appid + name with `last_fetched_at` NULL. Do not advance
`last_modified_since` until a full sweep completes, or a crash silently skips apps.
Verify exact parameter names against current Steam docs — this endpoint is
under-documented and has changed before.

**`details_refresh`** — the expensive one: `appdetails`, one request per app, unofficially
limited to roughly 200 requests per 5 minutes. Its work queue is:

```sql
SELECT app_id FROM games
WHERE (last_fetched_at IS NULL OR last_fetched_at < now() - INTERVAL '30 days')
  AND fetch_attempts < 3
ORDER BY last_fetched_at NULLS FIRST
LIMIT 200;
```

Uses neither cursor column; its row exists to record `last_run_at`. Pin `cc=us&l=english`,
since results are region-dependent, and record that choice. `appdetails` returns null for
delisted or region-locked apps, which `fetch_attempts` retires after three tries.

**`embedding`** — `WHERE embedding IS NULL AND last_fetched_at IS NOT NULL`, batched
through the model, vectors written back.

### Failure modes (rule #9)

- Updating a game and bumping `last_fetched_at` must be one transaction, or a crash
  either re-fetches forever or marks stale data fresh.
- Increment `fetch_attempts` **before** the HTTP call, not after, so an app that crashes
  the crawler is reliably retired instead of poisoning every run.

## Library sync

Triggered on login when `library_synced_at` is null or older than 7 days. No scheduler:
users who never log in cost nothing, and lazy sync is one conditional rather than a
subsystem. Add scheduling only if page-load latency measurably hurts.

The sync is a diff, not a replace — `DELETE` + re-`INSERT` would destroy `first_seen_at`,
which is the signal behind "you picked this up recently":

```sql
INSERT INTO owned_games (steam_id, app_id, playtime_minutes, playtime_2w_minutes)
VALUES (...)
ON CONFLICT (steam_id, app_id) DO UPDATE
SET playtime_minutes    = EXCLUDED.playtime_minutes,
    playtime_2w_minutes = EXCLUDED.playtime_2w_minutes,
    last_seen_at        = now();
```

Rows whose `last_seen_at` did not advance are no longer owned (refunded, family-shared,
revoked). Do not delete them — treat "not seen in the latest sync" as not owned. The
cause is usually an API hiccup, not a real change.

### Failure mode (rule #9)

`GetOwnedGames` can return a partial or empty list under load. Committing 40 of 900 games
and stamping the sync as fresh gives that user garbage for a week. So: fetch fully into
memory, sanity-check the result is not suspiciously empty when the user previously had
games, then write the games, `library_synced_at`, and the regenerated recommendations in
a single transaction — or write nothing.

## Recommendation algorithm

**Profile** — both representations derive from games the user has actually played:

- *Tag profile*: walk their top ~50 games by playtime, add each game's tags weighted by
  `sqrt(playtime_minutes)`, normalize. Square root so one 900-hour game does not define
  the entire profile.
- *Vector profile*: playtime-weighted centroid of the same games' embeddings, normalized
  to unit length.

**Candidates** — filter in SQL, score in Kotlin:

```sql
SELECT app_id, name, tags, embedding, review_count, positive_ratio,
       1 - (embedding <=> :profile_vector) AS vector_score
FROM games
WHERE review_count <= :max_reviews
  AND review_count >= 10
  AND positive_ratio >= 0.75
  AND embedding IS NOT NULL
  AND tags && :profile_tags
  AND app_id NOT IN (SELECT app_id FROM owned_games     WHERE steam_id = :me)
  AND app_id NOT IN (SELECT app_id FROM dismissed_games WHERE steam_id = :me)
ORDER BY embedding <=> :profile_vector
LIMIT 200;
```

`review_count >= 10` exists because a 3-review game at 100% positive outranks everything
on ratio while meaning nothing; the floor is what keeps asset flips out.

**Blending** — cosine similarity clusters in a narrow band (~0.6–0.9) while tag overlap
spreads across 0–1. Blending raw values lets the vector signal barely move the ranking.
Min-max normalize both across the 200 candidates first, then:

```
score = 0.6 * tagScore + 0.4 * vectorScore
```

Weights live in config. Tie-break toward the **lower** review count — the product thesis
expressed as a sort order.

**Cold start** (rule #8 — never 500, never a blank page): an empty, private, or entirely
unplayed library yields a meaningless profile. Fall back to the same filters sorted by
`positive_ratio` with no personalization. For a large library with zero playtime, build
the tag profile from owned-but-unplayed games instead.

## Testing (rules #1, #2)

Integration tests against real Postgres + pgvector via Testcontainers, not mocks. Seed a
catalog and a library, assert the ranked output. First cases:

- Owned games are excluded.
- Dismissed games are excluded.
- A 50k-review game never appears.
- A 4-review game never appears.
- An empty library returns the fallback, not an error.
- The sync diff preserves `first_seen_at` and updates playtime.
- A partial Steam response rolls back the whole sync.

## Out of scope

- Collaborative filtering. Needs a user base that does not exist yet; the `owned_games`
  `(app_id)` index is the only accommodation made for it.
- Scheduled background crawling infrastructure. Crawls are resumable and can be triggered
  manually until volume justifies a scheduler.
- Account deletion beyond a single `DELETE /me` endpoint relying on `ON DELETE CASCADE`.

## Unrelated issue found while exploring

`steam.api-key` is committed in plaintext in `src/main/resources/application.properties`
and is present in git history. It should be rotated and moved to an environment variable.
Tracked separately from this design.
