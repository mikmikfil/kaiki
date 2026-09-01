# Kaiki

Booking engine for cruises, day trips and private charters — a hosted booking engine plus operator back-office for boat operators, Greece first.

Multi-tenant Laravel application (operator panel + public API), an embeddable Preact widget, and a WordPress plugin.

## Getting started

Requirements: **PHP 8.4**, Composer 2, Node 22+. Nothing else — no Docker, no MySQL, no Redis.

```
composer install
composer setup
```

`composer setup` creates `.env`, generates the app key, creates `database/database.sqlite`, migrates, seeds, and builds the front-end assets. One command, no manual steps.

```
php artisan serve      # http://localhost:8000
composer test          # Pest
composer test:fast     # excludes the mysql, chromium and slow groups
```

### PHP extensions

The application needs `fileinfo`, `pdo_sqlite`, `sqlite3` and `zip`, plus `gd`, `intl` and `exif` for image handling and Greek formatting. On a stock Windows PHP build these are commented out in `php.ini` — uncomment them and restart your shell.

## Environments

Local, CI and production are deliberately different, and knowing which is which matters:

| | Local | CI | Production |
|---|---|---|---|
| Database | SQLite | MySQL 8 | MySQL 8 |
| Queue / cache | database driver | database + Redis job | Redis + Horizon |
| Runner | native PHP | GitHub Actions | Docker Compose (Hetzner) |

Every migration must run on **both** SQLite and MySQL. Some tests can only run in CI — the overselling concurrency test needs real row locking, `app/Domain` coverage needs PCOV, PDF snapshots need Chromium. They are tagged `mysql`, `slow` and `chromium` and excluded from `composer test:fast`.

## Documentation

| | |
|---|---|
| `docs/spec.md` | The contract. Numbered, testable requirements. **Read this first.** |
| `docs/data-model.md` | Schema, JSON shapes, state machines, migration ordering |
| `docs/api.md` | OpenAPI 3.1 for the public API v1 |
| `docs/adr/` | 23 accepted architecture decisions |
| `docs/ci.md` | What CI runs, which checks `main` requires, and how to refresh the schema snapshot |
| `docs/BRIEF.md` | The original brief — historical; where it differs from the spec, the spec wins |
| `CLAUDE.md` | Conventions, engine invariants, delegation map |

## Licence

Proprietary.
