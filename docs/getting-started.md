# Getting Started

## Requirements

- PHP **8.2** or **8.3**, with the `mbstring`, `pdo`, and `pdo_mysql` extensions.
- MySQL 8.0+ or MariaDB 11+. Loxodontu's schema alterations, error handling, and REST passthrough
  all assume MySQL-flavored SQL — other PDO drivers are not supported end to end.
- [Composer](https://getcomposer.org/).

## Install

```bash
git clone https://github.com/adaiasmagdiel/loxodontu.git
cd loxodontu
composer install
```

Copy the example environment file and fill in your database credentials:

```bash
cp .env.example .env
```

At minimum, set `DB_HOST_DEV`, `DB_PORT_DEV`, `DB_USERNAME_DEV`, `DB_PASSWORD_DEV`, and
`DB_DATABASE_DEV`. See [Configuration](configuration.md) for every variable. The database itself
doesn't need to exist yet — Loxodontu creates it automatically on first connection (MySQL/MariaDB
only) if the credentials have `CREATE DATABASE` privilege.

## Run the migrations

Migrations live in `database/migrations/` and are managed by
[`fullcrawl`](https://github.com/adaiasmagdiel/fullcrawl), installed as a dev dependency. Run them
against your real (dev/prod) database with:

```bash
php vendor/bin/fullcrawl --run
```

Other useful commands:

```bash
php vendor/bin/fullcrawl --status      # show which migrations have run
php vendor/bin/fullcrawl --rollback    # undo the last migration
php vendor/bin/fullcrawl --fresh       # drop everything and re-run from scratch (destructive, asks for confirmation)
php vendor/bin/fullcrawl --new "add_x_table"   # scaffold a new migration file
```

!!! warning
    Don't confuse this with `composer test:setup` — that command wipes and re-migrates the
    **test** database only (forcing `DB_MODE=testing` regardless of your `.env`). Never point it at
    a database you care about. See [Testing](testing.md).

## Run the app locally

```bash
php -S localhost:8000 router.php
```

`router.php` is the PHP built-in server's front controller — it mirrors what `.htaccess` does on
Apache (see [Deployment](deployment.md)), minus the HTTPS/`www` redirects that only make sense in
production.

Confirm it's up:

```bash
curl http://localhost:8000/api/health
# {"status":"ok"}
```

## Create your first project

```bash
# Register and log in
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{ "name": "Ada", "email": "ada@example.com", "password": "correct-horse" }'

curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{ "email": "ada@example.com", "password": "correct-horse" }'
# => { "token": "..." }
```

```bash
PLATFORM_TOKEN="<token from login>"

# Create a project and a table
curl -X POST http://localhost:8000/api/v1/projects \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "name": "My App" }'
# => { "id": 1, ... }

curl -X POST http://localhost:8000/api/v1/projects/1/tables \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "todos",
    "columns": [
      { "name": "title", "type": "text" },
      { "name": "done", "type": "boolean", "default_value": false }
    ]
  }'
```

Issue a project API key with `select`/`insert` permissions, then call the table straight through
[REST passthrough](api/rest-passthrough.md):

```bash
curl -X POST http://localhost:8000/api/v1/projects/1/keys \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "name": "app key", "permissions": ["select", "insert"] }'
# => { "key": "..." }  (shown only once)

PROJECT_KEY="<key from above>"

curl -X POST http://localhost:8000/api/v1/1/rest/todos \
  -H "Authorization: Bearer $PROJECT_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "title": "Ship Loxodontu docs" }'

curl http://localhost:8000/api/v1/1/rest/todos \
  -H "Authorization: Bearer $PROJECT_KEY"
```

From here: browse the full [API Overview](api/overview.md), lock down access with
[RLS policies](api/keys-and-rls.md), upload files with [Storage](api/storage.md), or read
[Deployment](deployment.md) to put this on shared hosting.
