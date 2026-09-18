# Indiescovery — Running it locally

Indiescovery recommends lesser-known indie games based on your Steam library. This page covers
getting a development copy running. There is no public deployment yet.

## Requirements

- PHP 8.3 or newer (developed on 8.5) with the `pdo_pgsql`, `pgsql`, `curl`, `mbstring` and
  `openssl` extensions enabled
- Composer 2
- Docker (for PostgreSQL)

On Windows, enable the Postgres extensions by adding these two lines to your `php.ini` and
restarting your terminal:

```ini
extension=pdo_pgsql
extension=pgsql
```

Check with `php -m | grep pgsql`.

## Get a Steam API key

1. Go to https://steamcommunity.com/dev/apikey and sign in.
2. Register a domain (`localhost` is accepted for development) and copy the key.
3. Keep it private. The key is tied to your Steam account and is not meant to be shared or
   committed.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d          # starts PostgreSQL on localhost:5432
php artisan migrate
```

Then open `.env` and set your key:

```
STEAM_API_KEY=your-key-here
```

`.env` is git-ignored — never put the key in `.env.example` or any file under `config/`.

## Run

```bash
php artisan serve
```

This starts the API on http://localhost:8000. The website itself is the separate `indiescovery-ui`
app: run `npm run dev` there and open http://localhost:5173. If the UI runs elsewhere, set
`FRONTEND_URL` in `.env`.

## Logging in

Click **connect steam** and sign in on Steam's own page; Indiescovery never sees your password. You
land on your profile.

What we read from Steam: your public profile (name, avatar, profile link, account creation date)
and your owned games with playtime. The library is saved at your first login and refreshed at most
once every 7 days, when you log in again. If Steam is down, you still log in and see your last
saved library.

**Seeing no games?** Your Steam game details are private. In Steam, go to Profile → Edit Profile →
Privacy Settings, set **Game details** to **Public**, then log out and in again.

## Tests

```bash
php artisan test
```

Tests never call Steam for real; all upstream responses are faked, and the suite runs against an
in-memory SQLite database, so neither Docker nor an API key is needed to run them.

## Troubleshooting

**Landing on `/?login=failed`.** The Steam sign-in was cancelled or could not be verified. Try
again; if it keeps failing, check that Steam is reachable.

**"0 games" for an account that owns games.** Your Steam game details are private; see "Logging in".

**`could not find driver`.** The `pdo_pgsql` extension is not enabled — see Requirements above.
