# UI ↔ Backend: Steam login and profile

**Date:** 2026-09-18
**Status:** Approved
**Repos:** `indiescovery` (Laravel backend), `indiescovery-ui` (React + Vite)

## Goal

"Connect steam" in the UI runs the real Steam OpenID login. On success the user lands on `/profile`,
which shows their real Steam identity and library, persisted in Postgres. Social features (bio,
followers, reviews, lists, taste) are out of scope.

## 1. Topology

- **Dev:** Vite (`:5173`) proxies `/api` and `/auth` to Laravel (`:8000`) and keeps the original
  `Host` header. The browser sees one origin, so the session cookie just works and no CORS is needed.
- **OpenID realm fix:** `SteamOpenId` builds `openid.realm` from the current request (`url('/')`)
  instead of `config('app.url')`. Otherwise `return_to` (built from the request host, `:5173`) falls
  outside the realm (`:8000`) and Steam rejects the login.
- **`FRONTEND_URL`** (config, Rule #11). The callback redirects to `FRONTEND_URL/profile` on success
  and `FRONTEND_URL/?login=failed` on failure.
- **Prod:** same pattern, one domain behind a reverse proxy. Not in scope.

## 2. Data and library sync

Implements the `library` part of `2026-09-12-data-storage-design.md`.

A migration replaces Laravel's unused default `users` table:

```
users(steam_id PK, persona_name, avatar_url, profile_url, steam_created_at,
      created_at, last_login_at, library_synced_at, library_private bool default false)
owned_games(steam_id FK users ON DELETE CASCADE, app_id, name, playtime_minutes, playtime_2w_minutes,
            first_seen_at, last_seen_at, PK (steam_id, app_id), index (app_id))
```

`owned_games.app_id` deliberately has no FK to `games`: users own games the catalog has not
discovered yet.

`SteamClient::getOwnedGames` distinguishes three outcomes instead of returning `[]` for all:
a list of games, **private** (Steam answers `response: {}`), or **failed** (`null`).

`LibrarySync` runs in the OpenID callback after verification:

1. Upsert the user row from `GetPlayerSummaries` and set `last_login_at`.
2. If `library_synced_at` is null or older than 7 days (config), fetch owned games.
3. Diff upsert into `owned_games` (`ON CONFLICT DO UPDATE`), which keeps `first_seen_at`. Rows not
   seen in this sync keep their old `last_seen_at`; they are never deleted.

Failure modes (Rule #9):

- **Steam fails:** keep the old snapshot and don't stamp `library_synced_at`. Login still succeeds.
- **Suspicious empty list:** if the user had games before and Steam returns none, treat it as a
  failure. It is usually an API hiccup, not a real change.
- **Private profile:** set `library_private = true`, stamp the sync and leave `owned_games` untouched.
- **Atomicity:** the owned games and `library_synced_at` are written in one transaction.
- **Player summary fails:** create the user with the `steam_id` as a placeholder name when no row
  exists, so login never fails because of Steam.

## 3. API

All responses are JSON, and errors never render an HTML 500 page (Rule #8).

`GET /api/me`, with a session:

```json
{
  "player": { "steamId": "7656...", "name": "…", "avatarUrl": "…", "profileUrl": "…", "steamCreatedAt": "2010-05-01T…" },
  "library": {
    "private": false, "syncedAt": "…", "gameCount": 312, "totalMinutes": 81234, "recentMinutes": 640,
    "games": [
      { "appId": 367520, "name": "Hollow Knight", "playtimeMinutes": 5400, "recentMinutes": 0,
        "capsuleUrl": "https://…/367520/header.jpg", "tags": ["Metroidvania"], "reviewCount": 415946, "positiveRatio": 0.97 }
    ]
  }
}
```

Games are sorted by playtime, descending. `tags`, `reviewCount` and `positiveRatio` are
left-joined from `games` and stay `null` until the crawl fills them. `name` comes from the catalog,
or from the `GetOwnedGames` response stored at sync time. `capsuleUrl` is built from the Steam CDN
base URL in config.

Without a session: `401 {"error":"unauthenticated"}`.

`POST /api/logout` invalidates the session and returns `204`. It is CSRF-protected: the UI echoes
the `XSRF-TOKEN` cookie as the `X-XSRF-TOKEN` header.

The `HomeController` smoke endpoint and the `/` route are removed.

## 4. UI

- `src/context/auth.tsx`: the `localStorage` flag goes away. On load the context calls
  `GET /api/me` and exposes `{ me, loading, logout }`.
- "Connect steam" buttons (in `LandingPage` and `TopNav`) do a full-page navigation to `/auth/steam`.
- `/profile` uses real data:
  - **Header:** avatar, persona name, "on Steam since <year>", a link to the Steam profile.
  - **Stats:** games owned, total hours, hours in the last two weeks.
  - **Shelf:** owned games by playtime, using capsule art.
  - **Private profile:** a message explaining how to make game details public on Steam.
  - **Removed:** bio, PRO badge, followers and following, follow and edit buttons, and the
    reviews, lists and taste tabs.
- `/profile` redirects to `/` on 401. When the landing page has `?login=failed`, it shows a short error.
- The other pages keep their mock data.

## Testing

- **Backend:** TDD feature tests with `Http::fake`. Cases: callback persists the user and library;
  resync is skipped within 7 days; `first_seen_at` is preserved; the suspicious empty list is
  ignored; a private profile is flagged; Steam down still logs in; `/api/me` returns 200 with a
  session and 401 without; logout works; the realm matches the request host.
- **UI:** the repo has no test setup, so the check is E2E in a browser.
- **Final E2E:** a real Steam login through the Vite proxy. Unhappy paths: a cancelled login, a
  private profile, Steam down (faked) and an expired session.

## Out of scope

Recommendations, the crawl (branch `feat/catalog-crawl`), a production deployment topology, and
social features.
