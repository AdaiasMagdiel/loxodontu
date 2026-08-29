<p align="center">
  <img src="banner-loxodontu.webp" alt="Loxodontu" width="100%">
</p>

Loxodontu is an open-source Backend-as-a-Service (BaaS) built with modern PHP. Designed as
a lightweight, self-hostable alternative to Supabase and Firebase, it provides developers
with instant APIs, database management, and essential backend infrastructure right out of
the box—leveraging the speed, simplicity, and ecosystem of PHP 8+.

Early development. Just started, no stable release yet, expect things to change.

**[Read the full documentation →](https://adaiasmagdiel.github.io/loxodontu/)**

## Testing

Feature tests run against a real MySQL/MariaDB database via [Pest](https://pestphp.com/). Set the
`DB_*_TEST` variables in `.env` (see `.env.example`), then:

```bash
composer test            # wipes + re-migrates the test DB, then runs the suite
composer test:coverage   # same, with a coverage report (requires Xdebug or PCOV)
```

CI (`.github/workflows/tests.yml`) runs the same suite on every push/PR against both MySQL and MariaDB.

## License

AGPL-3.0. See [LICENSE](LICENSE) and [COPYRIGHT](COPYRIGHT).
