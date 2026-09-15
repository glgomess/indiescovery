# Indiescovery

Indie game recommendations from your Steam library. Work in progress.

PHP 8.5 · Laravel 13 · PostgreSQL

- **Setup and usage:** [docs/user_documentation.md](docs/user_documentation.md)
- **Architecture and decisions:** [docs/internal_documentation.md](docs/internal_documentation.md)
- **Working agreements for AI agents:** [CLAUDE.md](CLAUDE.md)

```bash
composer install && cp .env.example .env && php artisan key:generate
docker compose up -d && php artisan migrate
php artisan test
php artisan serve
```
