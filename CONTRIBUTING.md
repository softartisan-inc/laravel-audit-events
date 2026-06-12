# Contributing

Thanks for considering a contribution! This package aims to be small, focused and
production-grade. PRs that keep it that way are very welcome.

## Getting started

```bash
git clone https://github.com/softartisan-inc/laravel-audit-events
cd laravel-audit-events
composer install
```

## Before you open a PR

Run the full quality gate locally — all must pass:

```bash
composer test       # Pest test suite
composer analyse    # PHPStan (level 5)
composer format     # Laravel Pint (PSR-12)
```

Or run lint (Pint + PHPStan) in one go:

```bash
composer lint
```

## Guidelines

- **Tests are required.** New behaviour or bug fixes must come with Pest tests.
  Tests run on PHP 8.2/8.3/8.4 × Laravel 11/12 in CI.
- **Follow TDD where practical** — write the failing test first.
- **Keep it portable.** The package must NOT depend on any specific tenancy or
  application package. Resolve table/column names via `config('audit-events.*')`;
  never hardcode them. Write/read on the current connection only.
- **No breaking changes** to the public API (`IsAuditable`, `saveHistory()`,
  `ModelAudit::record()`, scopes, `restore()`, `getDiff()`, the Artisan commands)
  without a major version bump and a migration path.
- **Migrations are additive and guarded** (`Schema::hasTable`/`hasColumn`/`hasIndex`).
- Keep PHPStan at **level 5** with zero errors.
- Update `README.md` / `USE-CASES.md` when you change or add a feature.

## Reporting bugs / requesting features

Open a GitHub issue with a minimal reproduction (a failing test is ideal).

## Security

Do not report vulnerabilities via public issues — see [`SECURITY.md`](./SECURITY.md).
