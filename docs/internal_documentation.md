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
| `HomeController` | `App\Http\Controllers\HomeController` | Still just a smoke endpoint. |
| `SteamLibraryPort` / `SteamOpenIdPort` / `SteamPlayerPort` | — | Dropped. Each had one implementation; `Http::fake()` covers the tests without them. Extract an interface when a second provider actually exists. |
| `ModuleStructureTests` (Spring Modulith) | — | No equivalent. Module boundaries are now convention, not enforced. |

Deliberately **not** carried over or added: any user model or user table, a custom auth guard, any
persistence of Steam data, and any recommendation logic. The data model is specified in
`superpowers/specs/2026-09-12-data-storage-design.md` and has not been implemented.

## The dotted-query-parameter hack

PHP rewrites dots in query parameter names to underscores, so `$request->query()` turns Steam's
`openid.claimed_id` into `openid_claimed_id`. Steam's `check_authentication` verification requires
the parameters echoed back under their original names, so `SteamAuthController::openIdParams()`
parses the raw `QUERY_STRING` by hand instead of using the request's parsed bag. This is covered by
`SteamAuthFlowTest::test_successful_callback_stores_steam_id_in_session`, which fails without it.

## Known fragilities

**A Steam outage is indistinguishable from an empty library.** `SteamClient::getOwnedGames()`
returns `[]` both when the profile is private and when Steam returns a 5xx or times out. That is
harmless today because nothing is persisted. It stops being harmless the moment libraries are
cached or stored: an outage would then overwrite a user's real library with an empty one. Before
adding persistence, `getOwnedGames()` must distinguish "Steam said you own nothing" from "Steam did
not answer" — return a result object or throw on transport failure — and the write path must skip
the update on failure rather than write an empty set. Marked in the code with a `ponytail:` comment.

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

## Configuration

Steam endpoints are read from `config/services.php` (`services.steam.api_url`, `login_url`, `media_url`),
overridable via `STEAM_API_URL`, `STEAM_LOGIN_URL`, `STEAM_MEDIA_URL`. Container-built services
(`SteamClient`, `SteamOpenId`) receive them via `#[Config]` constructor injection; the static
`SteamGame::fromApi` factory calls `config()` inline since the container never builds it. Protocol constants (OpenID 2.0
namespace URIs, the `claimed_id` pattern) stay in code: they are part of the spec, not deployment settings.

## Catalog crawl

Three scheduled artisan commands fill the `games` table (`routes/console.php`). Production needs one
cron entry, `* * * * * cd /app && php artisan schedule:run`; locally, run `php artisan schedule:work`.

| Command | Schedule | Source | Fills |
|---|---|---|---|
| `catalog:discover` | daily | `IStoreService/GetAppList` (needs `STEAM_API_KEY`) | `app_id`, `name` |
| `catalog:fetch-details` | every minute, 40 apps | Steam store `appdetails` (keyless) | description, release date, developer, publisher, price |
| `catalog:fetch-stats` | every minute, 50 apps | SteamSpy, or Steam as fallback | `tags`, `review_count`, `positive_ratio` |

Rate limits (observed, not documented): the Steam store allows about 200 requests per 5 minutes per IP;
SteamSpy about 1 request per second, which `fetch-stats` enforces with a sleep. Batch sizes live in
`config/catalog.php`. The first full crawl of ~120k games takes about 2-3 days.

**Resumability.** Progress lives in each row (`*_fetched_at`, `*_attempts`), not in the job. Any crash or
outage just leaves rows in the queue for the next run. Discovery keeps a page cursor in `crawl_state` and
only advances its `last_modified_since` watermark after a full sweep, so a crash never skips apps.

**Failures.** Attempts are incremented before each HTTP call, so an app that crashes the process is still
retired after `max_attempts` (3). An upstream outage (transport error, 429, 5xx) is different: it throws
`UpstreamUnavailable`, refunds the attempt and stops the batch, so rate limiting never retires games.
Apps a source has no data for (delisted, unknown to SteamSpy) record `*_error` and burn an attempt.

**SteamSpy fallback.** SteamSpy is a third-party service with no uptime guarantee. If it dies, set
`CATALOG_STATS_SOURCE=steam`: stats then come from Steam genres (coarse) plus `appreviews`. The queue is
the same rows, so the crawl continues where SteamSpy stopped. The fallback never overwrites
SteamSpy-filled rows. Switching back to `steamspy` re-queues every row with `stats_source = 'steam'` to
upgrade its tags. While on the fallback, `fetch-details` halves its batch because both share the store limit.
