# Deployment

Loxodontu is built to run on ordinary shared PHP hosting — the kind where a cPanel-style control
panel gives you a document root, `mod_rewrite`, and a way to schedule one file or one URL, but no
containers, process managers, or persistent workers. This page covers deploying it there, and to
Apache in general.

## Requirements on the host

- PHP **8.2** or **8.3**, with the `mbstring`, `pdo`, and `pdo_mysql` extensions enabled. These are
  the versions and extensions tested in CI.
- MySQL 8.0+ or MariaDB 11+, reachable from the host.
- Apache with `mod_rewrite` enabled — standard on virtually all shared hosting.

## Request lifecycle

There's no separate `public/` webroot — the document root points at the repository root itself,
where `.htaccess` and `index.php` live.

1. **`.htaccess`** forces HTTPS, strips a leading `www.`, disables directory listing, hides the
   `X-Powered-By` header, lets requests under `assets/` and `public/` through untouched, blocks
   direct access to every `.php` file except `index.php` (404), and rewrites everything else to
   `index.php`.
2. **`index.php`** loads `bootstrap.php`, builds the Erlenmeyer app, requires `routes/index.php`
   (which itself loads the 404/exception handler first), then dispatches.
3. **`bootstrap.php`** autoloads Composer, loads `.env` (non-fatal if missing), configures the
   database connection based on `DB_MODE`, and — only when `ENV=development` — turns on verbose
   PHP error display.

Locally, `router.php` stands in for `.htaccess` when using PHP's built-in server
(`php -S localhost:8000 router.php`); it mirrors the same rewrite behavior minus the
HTTPS/`www` redirects, which only make sense behind real TLS termination.

## Deploy steps

1. Upload the repository (or `git clone`) so the document root is the repo root.
2. `composer install --no-dev --optimize-autoloader` (drop `--no-dev` if you also want Pest
   available on the host, e.g. to run tests there).
3. Create `.env` from `.env.example` and fill in production values — at minimum the `DB_*_PROD`
   variables and `DB_MODE=production` (or any value other than `development`/`testing`). Set
   `ENV=production` and `DEBUG=false`. See [Configuration](configuration.md) for the full list.
4. Run migrations: `php vendor/bin/fullcrawl --run`. See [Getting Started](getting-started.md) for
   the full migration workflow — this is additive, not `--fresh`.
5. Confirm `mod_rewrite` is on and `.htaccess` is being read (some hosts disable
   `AllowOverride` by default — check with your provider if routes 404 unexpectedly).
6. Hit `/api/health` to confirm the app is serving:

    ```bash
    curl https://your-domain.example/api/health
    # {"status":"ok"}
    ```

## Shared-hosting specific gotchas

**`EDGE_PHP_BINARY`.** If you use [edge functions](edge-functions.md), shared hosts frequently
alias `PHP_BINARY` to something like `lsphp` or `php-cgi`, which can't be spawned as a plain CLI
interpreter the way the sandbox needs. Set `EDGE_PHP_BINARY` to the actual CLI binary path (ask
your host, or check what `php -v` resolves to over SSH) if edge functions fail to run.

**Cron.** Shared hosts typically let you schedule exactly one file or one URL — not a
long-running worker. Loxodontu's [cron jobs](cron-jobs.md) are designed around that: point the
host's cron scheduler at `worker.php`, either as a CLI command or a URL hit, and set
`CRON_WORKER_TOKEN` if the URL is reachable from the public internet.

**`command`-type cron jobs are disabled by default** (`CRON_ALLOW_COMMANDS=false`) because they
execute arbitrary shell commands on the host under the web server's user. Only enable this if you
trust every person who can create cron jobs on the instance.

**Only MySQL/MariaDB.** Schema alterations, error handling, and the REST passthrough layer all
assume MySQL-flavored SQL. If your host only offers PostgreSQL, Loxodontu won't work correctly
even though a Postgres DSN can technically be built.
