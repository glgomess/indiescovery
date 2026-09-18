# Internal documentation

## Stack

PHP 8.5 · Laravel 13.31 · PHPUnit 12.5 · PostgreSQL 17 (Docker) · Composer 2.10

Laravel's default PHPUnit setup is used as-is (no Pest). Tests run against in-memory SQLite; the
Postgres container is only needed by the running app.

## Migration from Kotlin/Spring (2026-09-15)

The project began as a Spring Modulith application in Kotlin and was migrated to Laravel because
Spring was disproportionate to the scope and the author is more fluent in PHP. Practically no
logic was lost — the Kotlin codebase was 15 files containing two Steam API calls and a hand-rolled
OpenID 2.0 login.

| Kotlin | PHP | Note |
|---|---|---|
| `SteamLibraryService` + `SteamPlayerService` | `App\Services\Steam\SteamClient` | Merged: both hit the same API with the same auth and error handling. |
| `SteamOpenIdService` | `App\Services\Steam\SteamOpenId` | |
| `SteamGame` / `SteamPlayer` data classes | same names, `readonly class` + `fromApi()` | |
| `SteamAuthController` | `App\Http\Controllers\SteamAuthController` | |
| `SecurityConfig` + `SteamAuthentication` | `App\Http\Middleware\RequireSteamAuth` + a session key | |
| `HomeController` | — | Smoke endpoint, removed on 2026-09-18 in favour of `/api/me`. |
| `SteamLibraryPort` / `SteamOpenIdPort` / `SteamPlayerPort` | — | Dropped. Each had one implementation; `Http::fake()` covers the tests without them. Extract an interface when a second provider actually exists. |
| `ModuleStructureTests` (Spring Modulith) | — | No equivalent. Module boundaries are now convention, not enforced. |

Deliberately **not** carried over or added: a custom auth guard and any recommendation logic. The
data model is specified in `superpowers/specs/2026-09-12-data-storage-design.md`; its `users` and
`owned_games` part is implemented (see "Login and library sync").

## The dotted-query-parameter hack

PHP rewrites dots in query parameter names to underscores, so `$request->query()` turns Steam's
`openid.claimed_id` into `openid_claimed_id`. Steam's `check_authentication` verification requires
the parameters echoed back under their original names, so `SteamAuthController::openIdParams()`
parses the raw `QUERY_STRING` by hand instead of using the request's parsed bag. This is covered by
`SteamAuthFlowTest::test_successful_callback_stores_steam_id_in_session`, which fails without it.

## Known fragilities

**Steam's login is OpenID 2.0, not OIDC.** The spec has been deprecated since 2014 and Steam is one
of the last large providers still running it. There is no fallback: if Steam retires the endpoint,
nobody can sign in. Accepted risk — a Steam-library product has no meaningful alternative identity
source.

**Verification requires a second round trip mid-callback.** Confirming the signature means posting
back to `steamcommunity.com/openid/login` while the user waits. If that call fails, `verify()`
returns `null` and the login is denied. This fails *closed*, which is the correct direction — a
transport failure must never be read as a valid signature — and is asserted by
`SteamOpenIdTest::test_it_fails_closed_when_steam_is_unreachable`.

**The Steam API key was committed to git history.** It lived in
`src/main/resources/application.properties` in the Kotlin tree. Deleting the file does not remove
it from history, so the key must be revoked at https://steamcommunity.com/dev/apikey and replaced.
The new key lives only in `.env`.

## Login and library sync

Spec: `superpowers/specs/2026-09-18-ui-auth-sync-design.md`.

**Topology.** The React app runs on Vite (`:5173`) and proxies `/api` and `/auth` to Laravel
(`:8000`) without rewriting `Host`, so the browser sees one origin: the Laravel session cookie just
works and there is no CORS. `SteamOpenId` builds `openid.realm` from the request (`url('/')`), not
`APP_URL`; otherwise `return_to` (on `:5173`) would fall outside the realm and Steam would refuse.

**Flow.** `/auth/steam` redirects to Steam. The callback verifies the signature, regenerates the
session, stores `steam_id`, runs `LibrarySync::run()` and redirects to `FRONTEND_URL/profile`
(`FRONTEND_URL/?login=failed` if verification fails). There is no Laravel auth guard: the session
key is the login, and `RequireSteamAuth` returns `401 {"error":"unauthenticated"}` for `api/*`.

**Sync.** `LibrarySync` upserts the `users` row from `GetPlayerSummaries` and sets `last_login_at`.
If `library_synced_at` is null or older than `config('library.resync_days')` (7), it fetches
`GetOwnedGames` and upserts `owned_games` on `(steam_id, app_id)`. `first_seen_at` is insert-only
(excluded from the upsert's update columns); rows missing from a later sync are never deleted.
The upsert and the `library_synced_at` stamp share one transaction.

**Failure modes.** `SteamClient::getOwnedGames()` returns games, `SteamClient::PRIVATE`
(`response: {}`) or `null` (Steam failed).
- Steam failed: keep the old snapshot, don't stamp the sync, login still succeeds.
- Empty list for a user who already has rows: treated as a Steam hiccup, same as failure.
- Private: set `library_private`, stamp the sync, leave rows untouched.
- Player summary failed on first login: user created with `persona_name = steam_id`.
- Anything else thrown inside the sync is caught and logged, so the callback never 500s.

**Catalog join.** `/api/me` left-joins `owned_games` to the crawl's `games` table for tags and review
stats. `games` lives on `feat/catalog-crawl`; until it merges, `MeController` checks
`Schema::hasTable('games')` (marked `ponytail:`), and `MeApiTest` creates a minimal `games` table.

## Configuration

Steam endpoints are read from `config/services.php` (`services.steam.api_url`, `login_url`, `media_url`,
`capsule_url`), overridable via `STEAM_API_URL`, `STEAM_LOGIN_URL`, `STEAM_MEDIA_URL`, `STEAM_CAPSULE_URL`.
`FRONTEND_URL` (`config('app.frontend_url')`) is where the login callback sends the browser. Container-built services
(`SteamClient`, `SteamOpenId`) receive them via `#[Config]` constructor injection; the static
`SteamGame::fromApi` factory calls `config()` inline since the container never builds it. Protocol constants (OpenID 2.0
namespace URIs, the `claimed_id` pattern) stay in code: they are part of the spec, not deployment settings.
