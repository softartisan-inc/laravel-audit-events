# Changelog

All notable changes to `laravel-audit-events` will be documented in this file.

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

* **`AuditContext`** — inject a causer ID and extra payload for queue jobs where `Auth::id()` is `null`. Use `AuditContext::actingAs($userId)` at the start of your job and `AuditContext::reset()` at the end.
* **`ModelAudit::record()`** — record free-standing semantic events without an Eloquent model anchor (login, logout, CSV export, PDF generation, etc.). Free events bypass the events whitelist.
* **`TracksRelationChanges` trait** — manually record pivot/sync operations as `relation.synced` audit events. Use alongside `IsAuditable` on models with many-to-many relations.
* **`context` JSON column** — every audit record now has a `context` column for arbitrary payload. Available in `saveHistory()`, `ModelAudit::record()`, and `recordRelationAudit()`.
* **Deep JSON diff** — `getDiff()` now recursively diffs array/JSON fields to show exactly which sub-keys changed. Configurable via `json_diff.enabled` and `json_diff.max_depth`.
* **`audit-events:stats` command** — displays totals, event breakdown, top 5 audited models, date range, and table size.
* **Pruning** — `ModelAudit` now implements `Prunable`. Set `pruning.enabled = true` and the service provider auto-schedules `model:prune` daily. `keep_for_days` is read dynamically for multi-tenant support.
* **New migrations**:
  * `create_audit_events_table` — replaces `create_laravel_model_audits_table` (new installs).
  * `rename_model_audits_to_audit_events_table` — safe rename for v1.x upgrades.
  * `add_context_to_audit_events_table` — additive migration for existing `audit_events` tables without the `context` column.
* **`ModelAuditFactory`** — full factory with `created()`, `updated()`, `deleted()`, `free()`, and `forModel()` states.
* **Events whitelist** now only applies to automatic Eloquent events. `saveHistory()` and `ModelAudit::record()` are never blocked.

### Improvements

* `IsAuditable::saveHistory()` now accepts a `$context` array parameter.
* `ModelAudit` uses `getCasts()` override for reliable JSON cast behaviour at construction time (fixes factory `Array to string conversion`).
* `IsAuditable::updated` listener now uses cast-aware `getAttribute()` for new values, ensuring JSON/array fields are stored as decoded arrays (fixes deep diff on JSON columns).
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
