# Testing

Feature tests run against a real MySQL/MariaDB database via [Pest](https://pestphp.com/). Set the
`DB_*_TEST` variables in `.env` (see `.env.example`), then:

```bash
composer test            # wipes + re-migrates the test DB, then runs the suite
composer test:coverage   # same, with a coverage report (requires Xdebug or PCOV)
```

CI (`.github/workflows/tests.yml`) runs the same suite on every push/PR against both MySQL and MariaDB.

The suite is split into `tests/Unit`, `tests/Feature` and `tests/Security`. Security tests reproduce
known attacks — cross-tenant RLS expressions, local-file and internal-network requests from cron
jobs, script execution through uploaded files, login brute force, sandbox escapes — and must keep
failing the attack. Add one there whenever you fix a security issue.

The edge function sandbox (extra pipes, `open_basedir`) is only supported on Linux/macOS hosts; run
those tests under WSL or rely on CI when developing on Windows.
