# UI Auth Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Connect steam" in the UI runs the real Steam login, persists the user and library, and `/profile` renders them.

**Architecture:** Laravel owns auth (session cookie) and exposes `GET /api/me` and `POST /api/logout`. The Vite dev server proxies `/api` and `/auth` to Laravel, so the browser sees one origin. The library is synced into Postgres at login and refreshed lazily after 7 days.

**Tech Stack:** PHP 8.5, Laravel 13, PHPUnit 12 (backend); React 19, react-router 8, Vite 8, TypeScript (UI).

**Spec:** `docs/superpowers/specs/2026-09-18-ui-auth-sync-design.md`

## Global Constraints

- Backend worktree: `C:/Users/guila/OneDrive/Documentos/Github/indiescovery-wt-ui-sync`, branch `feat/ui-auth-sync`.
- UI worktree: `C:/Users/guila/OneDrive/Documentos/Github/indiescovery-ui-wt-auth`, branch `feat/steam-auth`.
- Backend CLAUDE.md rules apply: TDD, integration tests, a 1-sentence docblock on every class and method, no hardcoded URLs (config with `#[Config]` injection), no 500s, Conventional Commits in small groups.
- UI CLAUDE.md rules apply: simplicity, E2E in the browser.
- Run backend tests with `php artisan test` (in-memory SQLite, `Http::fake`). Never commit `.env`.
- Commit messages end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. Do not push.

## API contract (shared by both tracks)

```ts
// GET /api/me  -> 200 Me | 401 {"error":"unauthenticated"}
type Me = {
  player: { steamId: string; name: string; avatarUrl: string | null; profileUrl: string | null; steamCreatedAt: string | null };
  library: {
    private: boolean; syncedAt: string | null;
    gameCount: number; totalMinutes: number; recentMinutes: number;
    games: { appId: number; name: string; playtimeMinutes: number; recentMinutes: number;
             capsuleUrl: string; tags: string[] | null; reviewCount: number | null; positiveRatio: number | null }[];
  };
};
// POST /api/logout (header X-XSRF-TOKEN = decodeURIComponent(XSRF-TOKEN cookie)) -> 204
// GET /auth/steam -> 302 to Steam; callback -> 302 FRONTEND_URL/profile | FRONTEND_URL/?login=failed
```

---

## Track A: Backend (`indiescovery-wt-ui-sync`)

### Task A1: Realm from the request, and redirect to the frontend

**Files:** Modify `app/Services/Steam/SteamOpenId.php`, `app/Http/Controllers/SteamAuthController.php`, `config/app.php`, `.env.example`. Test: `tests/Feature/SteamOpenIdTest.php`, `tests/Feature/SteamAuthFlowTest.php`.

- [ ] Change the test at `SteamOpenIdTest.php:52` to expect the realm to be `url('/')`. Add a test that calls `loginUrl()` during a request to `http://localhost:5173/auth/steam` (`$this->get(...)` with the Host header) and asserts that `openid.realm` is `http://localhost:5173` and `openid.return_to` starts with the realm.
- [ ] Change the callback tests: a successful callback redirects to `config('app.frontend_url').'/profile'`; a rejected one redirects to `config('app.frontend_url').'/?login=failed'`.
- [ ] Run `php artisan test`. The changed tests fail.
- [ ] Implement:
  - `'openid.realm' => url('/')`.
  - In `config/app.php`, `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173')`. Add `FRONTEND_URL=http://localhost:5173` to `.env.example`.
  - The controller gets `#[Config('app.frontend_url')] private readonly string $frontendUrl` and uses `redirect()->away(...)`.
- [ ] Tests pass. Commit: `fix: build OpenID realm from request host` and `feat: redirect Steam callback to frontend`.

### Task A2: Users and owned_games schema

**Files:** Create `database/migrations/2026_09_18_100000_create_steam_users_tables.php`. Modify `app/Models/User.php`. Test: covered by A4.

- [ ] Migration `up()`: `Schema::dropIfExists('users')`, then create:
  - `users`: `string steam_id` primary; `string persona_name`; nullable `avatar_url`, `profile_url`; nullable `timestamp steam_created_at`; `timestamp last_login_at`; nullable `timestamp library_synced_at`; `boolean library_private` default false; `timestamps()`.
  - `owned_games`: `string steam_id` FK to `users.steam_id` with `cascadeOnDelete`; `unsignedInteger app_id`; `string name`; `unsignedInteger playtime_minutes`; nullable `unsignedInteger playtime_2w_minutes`; `timestamp first_seen_at`; `timestamp last_seen_at`; `primary(['steam_id','app_id'])`; `index('app_id')`.
  - Also drop `sessions.user_id`'s dependence, if any: leave the `sessions` table as is. Its `user_id` column is unused.
- [ ] `down()`: drop both tables and recreate Laravel's default `users` table (copy it from `0001_01_01_000000_create_users_table.php`).
- [ ] `User` model: `$primaryKey = 'steam_id'`, `$keyType = 'string'`, `$incrementing = false`, `$guarded = []`, and casts for the timestamps plus `library_private` as boolean. Remove `password` and `email` handling; keep it a plain `Model`, since no Laravel auth guard is used. Delete `database/factories/UserFactory.php` if nothing uses it (`grep -r UserFactory`).
- [ ] `php artisan test` stays green. Commit: `feat: add steam users and owned_games tables`.

### Task A3: getOwnedGames distinguishes private from failed

**Files:** Modify `app/Services/Steam/SteamClient.php`. Test: `tests/Feature/SteamClientTest.php`.

- [ ] Tests:
  - `{"response":{}}` returns `SteamClient::PRIVATE`.
  - HTTP 500 returns `null`.
  - `{"response":{"game_count":0}}` returns `[]`.
  - Normal payload returns `SteamGame[]`.
- [ ] Implement: `getOwnedGames(): array|string|null`, with `public const PRIVATE = 'private'`. Distinguish outcomes by checking `$response === []` (failure, because `get()` returns `[]` on errors) versus a `response` without `game_count` (private). Remove the `ponytail:` docblock comment that this resolves. Update callers (`HomeController` is deleted in A5, so only tests).
- [ ] Commit: `feat: distinguish private libraries from Steam failures`.

### Task A4: LibrarySync at login

**Files:** Create `app/Services/Library/LibrarySync.php`, `config/library.php` (`'resync_days' => 7`). Modify `SteamAuthController::callback`. Test: create `tests/Feature/LibrarySyncTest.php`, using `RefreshDatabase` and exercising it through the callback route: fake `*/openid/login` with `is_valid:true`, plus `*/GetPlayerSummaries/*` and `*/GetOwnedGames/*`.

**Interfaces:** Produces `LibrarySync::run(string $steamId): void`, which never throws. It logs a warning and returns on any Steam failure.

- [ ] Tests, one per spec case:
  1. First login creates the user row (persona, avatar, `steam_created_at` from `timecreated`) and one `owned_games` row per game, and stamps `library_synced_at`.
  2. A login within 7 days does not call `GetOwnedGames` (`Http::assertNotSent`) but does update `last_login_at`.
  3. A resync after 8 days (`$this->travel(8)->days()`) updates playtime and keeps `first_seen_at`.
  4. If the user had games and Steam returns `game_count:0`, the rows are untouched and `library_synced_at` is not advanced.
  5. A private profile sets `library_private=true`, stamps the sync and leaves the rows untouched.
  6. With GetOwnedGames at 500: login still redirects to `/profile`, the user exists and `library_synced_at` is null.
  7. With GetPlayerSummaries at 500 and no user row: the user is created with `persona_name = steam_id`.
- [ ] Implement:
  - Upsert the user with `updateOrCreate`, keeping the existing persona when the summary is null.
  - Check staleness against `config('library.resync_days')`.
  - Then do the following in `DB::transaction`: an `owned_games` upsert (`upsert(rows, ['steam_id','app_id'], ['name','playtime_minutes','playtime_2w_minutes','last_seen_at'])`, where rows set `first_seen_at=now()` only for inserts, which works because upsert's update list excludes it), plus the `users` update of `library_synced_at` and `library_private`.
  - Call it from `callback` after `session()->put(...)`, wrapped in `try/catch (Throwable)` that logs, so login never 500s.
- [ ] Commit: `feat: sync Steam user and library on login`.

### Task A5: /api/me and /api/logout

**Files:** Create `app/Http/Controllers/MeController.php` (`show`, `logout`). Modify `routes/web.php` and `app/Http/Middleware/RequireSteamAuth.php`. Delete `HomeController.php` and its tests in `SteamAuthFlowTest`. Modify `bootstrap/app.php` if needed. Add `'capsule_url' => env('STEAM_CAPSULE_URL', 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps')` to `config/services.php` under steam, and add it to `.env.example`. Test: create `tests/Feature/MeApiTest.php`.

- [ ] Tests:
  - Without a session, `getJson('/api/me')` returns 401 `{"error":"unauthenticated"}`.
  - With `withSession(['steam_id'=>...])` plus seeded `users`, `owned_games` and one `games` catalog row, it returns 200 and exactly the contract JSON. Games are sorted by playtime descending; tags, reviewCount and positiveRatio come from `games`; they are null for a game missing from the catalog; `capsuleUrl` is `{capsule_url}/{appId}/header.jpg`; `gameCount`, `totalMinutes` and `recentMinutes` are aggregated.
  - A session steam_id with no user row returns 401.
  - `postJson('/api/logout')` with a session returns 204, and the session no longer has `steam_id`.
  - An unknown `/api/nope` returns a JSON 404 (the existing `shouldRenderJsonWhen` handles it).
- [ ] Implement:
  - `RequireSteamAuth` returns `response()->json(['error'=>'unauthenticated'], 401)` for `api/*` or `expectsJson()` requests, and redirects otherwise.
  - Routes: `Route::middleware(RequireSteamAuth::class)->group(fn () => [Route::get('/api/me', [MeController::class,'show']), Route::post('/api/logout', [MeController::class,'logout'])])`.
  - `show`: a query-builder left join from `owned_games` to `games` on `app_id`; json-decode tags.
  - `logout`: `$request->session()->invalidate(); regenerateToken(); return response()->noContent();`.
  - The `games` table does not exist on this branch (the crawl is on another branch). Guard the join with `Schema::hasTable('games')`, marked with a `ponytail:` comment to remove once `feat/catalog-crawl` merges. The test seeds `games` by creating the table inline in the test when it is missing.
- [ ] Commit: `feat: add /api/me and /api/logout` and `refactor: remove HomeController smoke endpoint`.

### Task A6: Docs

- [ ] `docs/internal_documentation.md`: add a "Login and library sync" section (flow, the 7-day resync, the failure modes, the Vite proxy, `FRONTEND_URL`). `docs/api_documentation.md`: document `/api/me` and `/api/logout` for customers, with no internals. `docs/user_documentation.md`: add login, what is read from Steam, and how to set a profile public.
- [ ] Commit: `docs: document login, library sync and profile API`.

---

## Track B: UI (`indiescovery-ui-wt-auth`)

### Task B1: Vite proxy and API client

**Files:** Modify `vite.config.ts`. Create `src/lib/api.ts`.

- [ ] In `vite.config.ts`, add `server: { proxy: { '/api': 'http://localhost:8000', '/auth': 'http://localhost:8000' } }`. Leave `changeOrigin` false, so Laravel sees Host `localhost:5173`.
- [ ] `src/lib/api.ts`: export the `Me` type from the contract above; `fetchMe(): Promise<Me | null>` (401 returns null, any other failure throws); and `logout(): Promise<void>` (a POST sending `X-XSRF-TOKEN`, read from `document.cookie` with `decodeURIComponent`, plus `credentials: 'same-origin'`).
- [ ] Commit: `feat: proxy backend and add API client`.

### Task B2: Real auth context and connect buttons

**Files:** Modify `src/context/auth.tsx`, `src/components/landing/LandingPage.tsx`, `src/components/layout/TopNav.tsx`.

- [ ] `AuthProvider` state: `{ me: Me | null, loading: boolean, logout(): Promise<void> }`. It fetches once on mount. On a fetch error it sets `me=null` and logs the error, and never crashes. `logout` calls the API, sets `me=null` and navigates to `/`.
- [ ] Every "connect steam" CTA (LandingPage `onCta`, TopNav link) does `window.location.href = '/auth/steam'`. Remove the old fake `login()`.
- [ ] TopNav: when logged in, show avatar and name linking to `/profile`, plus a logout button; otherwise show the connect button.
- [ ] LandingPage: if `?login=failed`, show a small error line ("Steam login didn't complete. Try again.").
- [ ] Commit: `feat: use backend session for auth`.

### Task B3: Real profile page

**Files:** Modify `src/components/profile/ProfilePage.tsx`.

- [ ] While `loading`, show a minimal skeleton. If there is no `me`, `<Navigate to="/" replace />`.
- [ ] Header: avatar `<img>` (fall back to initials), name, "on Steam since {year}" (omitted when null), and a link "view on steam ↗" to `profileUrl`.
- [ ] Stats row with 3 cells: games owned, hours played (`Math.round(totalMinutes/60)`), last 2 weeks (hours).
- [ ] Shelf only (no tabs): grid of `games`, with a capsule `<img loading="lazy">` (`aspectRatio 460/215`), name, hours, and up to 2 tags when present. On image error, fall back to `VideoPH`.
- [ ] If `library.private`: a card saying "Your Steam game details are private. Set *Game details* to Public in Steam → Profile → Privacy Settings, then log in again." With 0 games and not private: "No games in your library yet."
- [ ] Delete `LegacyPlayPatterns`, the social stats, the follow and edit buttons, and the reviews, lists and taste tabs.
- [ ] `npm run build` and `npm run lint` are clean. Commit: `feat: render real Steam profile`.

---

## Final E2E (main session, after both tracks)

1. `docker compose up -d`, then in the backend worktree `php artisan migrate`, `php artisan serve`. In the UI worktree, `npm run dev`.
2. Open `http://localhost:5173`, click connect steam, sign in on Steam, and land on `/profile` with real data. Check the rows in `users` and `owned_games`.
3. Unhappy paths:
   - Cancel on Steam: `/?login=failed`.
   - Delete the session cookie and reload `/profile`: redirect to `/`.
   - Set `STEAM_API_URL` to a dead host and log in again: the profile still renders the old snapshot.
   - Log out: `/api/me` returns 401.
