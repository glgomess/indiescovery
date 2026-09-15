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

Open http://localhost:8000. You will be redirected to Steam to sign in. After signing in you land
back on a plain text page reporting your Steam persona name and how many games you own — this is a
temporary smoke endpoint that proves the Steam integration works, not the real product.

## Tests

```bash
php artisan test
```

Tests never call Steam for real; all upstream responses are faked, and the suite runs against an
in-memory SQLite database, so neither Docker nor an API key is needed to run them.

## Troubleshooting

**Redirected back to the Steam login in a loop.** Your `APP_URL` must match the address you browse
to. Steam validates the OpenID realm against it, so browsing to `127.0.0.1` while `APP_URL` says
`localhost` will fail.

**"0 games" for an account that owns games.** Your Steam profile's game details are set to private.
Steam returns an empty response for private profiles rather than an error.

**`could not find driver`.** The `pdo_pgsql` extension is not enabled — see Requirements above.
