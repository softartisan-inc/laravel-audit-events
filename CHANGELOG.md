# Changelog

All notable changes to `laravel-audit-events` will be documented in this file.

## v2.1.0 - 2026-03-29

### New Features

* **Cryptographic integrity** — every new audit record receives an HMAC-SHA256 signature covering its full payload. A hash chain is maintained per `(auditable_type, auditable_id)` tuple: each record includes the previous record's signature as `previous_hash`, making insertion, deletion, or reordering of rows detectable within any model's history. Opt-in via `integrity.enabled = true`.
  * `ModelAudit::isSigned()` — returns `true` if the record carries a signature.
  * `ModelAudit::verifySignature()` — recomputes and compares the HMAC for a single record.
  * `AuditSignatureService` — pure computation layer (deterministic canonical JSON, `hash_hmac`, base64-APP_KEY decoding).
  * New command: `audit-events:verify` — verifies all signed records in bulk (chunked, 500/batch). Options: `--model`, `--id`, `--from`, `--until`, `--fail-fast`. Exit codes: `0` = all pass, `1` = failures found.
  * New migration: `add_signature_to_audit_events_table` — adds `signature` (varchar 64) and `previous_hash` (varchar 64) columns, nullable (existing records coexist as "unsigned").
  * Configurable via `integrity.key` (defaults to `APP_KEY`) and `integrity.algorithm` (default `sha256`).

* **Cold archiving** — move audit records older than a configurable threshold to cold storage instead of deleting them. Opt-in via `archive.enabled = true`.
  * `database` driver: transactional bulk-insert into `audit_events_archive` then delete from `audit_events`. Preserves all columns including `signature` and `previous_hash`.
  * `json_file` driver: appends JSONL lines to daily files under a configurable path. File format: one JSON object per line, `archived_at` added. Ready for gzip/S3 upload.
  * New command: `audit-events:archive` — options: `--days`, `--driver`, `--dry-run`, `--chunk`, `--model`.
  * `audit-events:stats` now displays archive record count, oldest archived, and newest archived when `archive.enabled = true`.
  * New migration: `create_audit_events_archive_table` — identical schema to `audit_events` plus `archived_at` timestamp.
  * Configurable via `archive.archive_after_days` (default 90), `archive.driver`, `archive.table_name`, `archive.path`.

### Improvements

* `create_audit_events_table` migration now includes `signature` and `previous_hash` columns for clean installations.
* `ModelAudit::$fillable` extended with `signature` and `previous_hash`.
* `LaravelAuditEventsServiceProvider` registers `AuditSignatureService` as a singleton.
* GrumPHP `ignore_patterns` and PHPStan `excludePaths` corrected to exclude `tests/Feature/*` from staged-file analysis.

### Test suite

* 50 tests, 120 assertions — all passing
* PHPStan level 5 — 0 errors
* New test files: `AuditSignatureTest`, `AuditEventsVerifyCommandTest`, `AuditEventsArchiveCommandTest`

---

## v2.0.0 - 2026-03-29

### Breaking Changes

* **Package renamed**: `softartisan/laravel-model-audits` → `softartisan/laravel-audit-events`. Update your `composer.json` accordingly.
* **Namespace changed**: `SoftArtisan\LaravelModelAudits` → `SoftArtisan\LaravelAuditEvents`. Update all `use` statements.
* **Config key changed**: `model-audits` → `audit-events`. Publish the new config file: `php artisan vendor:publish --tag="laravel-audit-events-config"`.
* **Table renamed**: `model_audits` → `audit_events`. Run the bundled `rename_model_audits_to_audit_events_table` migration when upgrading from v1.x.
* **Artisan command renamed**: `model-audits:stats` → `audit-events:stats`.
* **Service provider renamed**: `LaravelModelAuditsServiceProvider` → `LaravelAuditEventsServiceProvider`.
* **Facade renamed**: `LaravelModelAudits` → `LaravelAuditEvents`.

### New Features

* **`AuditContext`** — inject a causer ID and extra payload for queue jobs where `Auth::id()` is `null`. Use `AuditContext::actingAs($userId)` at the start of your job's `handle()` method and `AuditContext::reset()` at the end.
* **`ModelAudit::record()`** — record free-standing semantic events without an Eloquent model anchor (login, logout, CSV export, PDF generation, etc.). Free events bypass the events whitelist.
* **`TracksRelationChanges` trait** — manually record pivot/sync operations as `relation.synced` audit events. Use alongside `IsAuditable` on models with many-to-many relations.
* **`context` JSON column** — every audit record now has a `context` column for arbitrary payload. Available in `saveHistory()`, `ModelAudit::record()`, and `recordRelationAudit()`.
* **Deep JSON diff** — `getDiff()` now recursively diffs array/JSON fields to show exactly which sub-keys changed. Configurable via `json_diff.enabled` and `json_diff.max_depth`.
* **`audit-events:stats` command** — displays totals, event breakdown, top 5 audited models, date range, and table size.
* **Pruning** — `ModelAudit` now implements `Prunable`. Set `pruning.enabled = true` and the service provider auto-schedules `model:prune` daily. `keep_for_days` is read dynamically for multi-tenant support.
* **New migrations**: `create_audit_events_table`, `rename_model_audits_to_audit_events_table`, `add_context_to_audit_events_table`.
* **`ModelAuditFactory`** — full factory with `created()`, `updated()`, `deleted()`, `free()`, and `forModel()` states.
* **Events whitelist** now only applies to automatic Eloquent events. `saveHistory()` and `ModelAudit::record()` are never blocked.

### Improvements

* `IsAuditable::saveHistory()` now accepts a `$context` array parameter.
* `ModelAudit` uses `getCasts()` override for reliable JSON cast behaviour at construction time.
* `IsAuditable::updated` listener now uses cast-aware `getAttribute()` for new values.
* Service provider registers `AuditContext` as a singleton.

**Full Changelog**: https://github.com/softartisan-inc/laravel-audit-events/compare/v1.1.1...v2.0.0

## v1.1.1 - 2025-12-08

### What's Changed

* Add hidden audit fields on model properties by @henoc35 in https://github.com/softartisan-inc/laravel-model-audits/pull/4

**Full Changelog**: https://github.com/softartisan-inc/laravel-model-audits/compare/v1.1.0...v1.1.1

## v1.1.0 - 2025-12-07

### What's Changed

* chore: Configure GrumPHP to enforce code quality standards by @henoc35 in https://github.com/softartisan-inc/laravel-model-audits/pull/3

**Full Changelog**: https://github.com/softartisan-inc/laravel-model-audits/compare/v1.0.0...v1.1.0

## v1.0.0 - 2025-12-06

### What's Changed

* Update run test action by @henoc35 in https://github.com/softartisan-inc/laravel-model-audits/pull/2

### New Contributors

* @henoc35 made their first contribution in https://github.com/softartisan-inc/laravel-model-audits/pull/2

**Full Changelog**: https://github.com/softartisan-inc/laravel-model-audits/commits/v1.0.0
