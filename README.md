# Laravel Audit Events

[![Latest Version on Packagist](https://img.shields.io/packagist/v/softartisan/laravel-audit-events.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-audit-events)
[![Tests](https://img.shields.io/github/actions/workflow/status/softartisan-inc/laravel-audit-events/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/softartisan-inc/laravel-audit-events/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/softartisan/laravel-audit-events.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-audit-events)

A production-ready Laravel package that **automatically audits Eloquent model changes**, records **free-standing semantic events**, guarantees **cryptographic integrity** of every audit record, and provides a **cold archiving** strategy for long-term retention — designed for ERP systems and compliance-sensitive applications.

> **v2.0 — breaking changes**: Package renamed from `softartisan/laravel-model-audits` to `softartisan/laravel-audit-events`. See the [Upgrade Guide](#upgrade-from-v1x) below.

> 📖 **Looking for "how do I do X?"** See the scenario-driven
> **[Use-Case Cookbook → `USE-CASES.md`](./USE-CASES.md)** — every use case
> (auto-audit, manual/free events, jobs, revert, export, multi-tenant isolation,
> impersonation, integrity, retention, frontend rendering…) with copy-paste code.

---

## Features

- Automatic audit trail for `created`, `updated`, `deleted`, `restored` Eloquent events
- `AuditContext::actingAs()` — inject the causer in queue jobs where `Auth::id()` is `null`
- `ModelAudit::record()` — record free-standing events (login, export, PDF…) without an Eloquent anchor
- `saveHistory()` — manually record a semantic event bound to a specific model
- `TracksRelationChanges` — track pivot/sync relation changes (many-to-many)
- Deep JSON diff — recursively diff array/JSON fields to pinpoint sub-key changes
- Global + per-model attribute masking (passwords, tokens, credit cards…)
- **Cryptographic integrity** — HMAC-SHA256 signature + hash chain per model, tamper-evident audit trail
- **Cold archiving** — move old records to a dedicated table or daily JSONL files instead of deleting them
- Configurable pruning with per-tenant retention
- `audit-events:stats` — audit statistics at a glance
- `audit-events:verify` — bulk integrity verification
- `audit-events:archive` — archive old records to cold storage
- MCP server integration (optional, requires `laravel/mcp`)
- PHP 8.4 · Laravel 12 · Pest · PHPStan level 5

---

## Table of Contents

- [Installation](#installation)
- [Upgrade from v1.x](#upgrade-from-v1x)
- [Basic Usage](#basic-usage)
- [Querying Audit History](#querying-audit-history)
- [Restore a Model](#restore-a-model)
- [Attribute Masking](#attribute-masking)
- [AuditContext — Queue Jobs](#auditcontext--queue-jobs)
- [Free Events — ModelAudit::record()](#free-events--modelauditrecord)
- [saveHistory() — Manual Model Events](#savehistory--manual-model-events)
- [TracksRelationChanges](#tracksrelationchanges)
- [Deep JSON Diff](#deep-json-diff)
- [Cryptographic Integrity](#cryptographic-integrity)
- [Cold Archiving](#cold-archiving)
- [Pruning](#pruning)
- [Artisan Commands](#artisan-commands)
- [Configuration Reference](#configuration-reference)
- [Testing](#testing)
- [Changelog](#changelog)

---

## Installation

```bash
composer require softartisan/laravel-audit-events
```

Publish the config and run the migrations:

```bash
php artisan vendor:publish --tag="laravel-audit-events-config"
php artisan migrate
```

---

## Upgrade from v1.x

> **Breaking changes** in v2.0:
> - Package renamed: `softartisan/laravel-model-audits` → `softartisan/laravel-audit-events`
> - Namespace changed: `SoftArtisan\LaravelModelAudits` → `SoftArtisan\LaravelAuditEvents`
> - Config key changed: `model-audits` → `audit-events`
> - Artisan command renamed: `model-audits:stats` → `audit-events:stats`
> - Audit table renamed: `model_audits` → `audit_events`

**Step 1** — update your `composer.json`:

```bash
composer require softartisan/laravel-audit-events:^2.0
```

**Step 2** — update all `use` statements and config references in your app:

```php
// Before
use SoftArtisan\LaravelModelAudits\Concerns\IsAuditable;
config('model-audits.table_name');

// After
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;
config('audit-events.table_name');
```

**Step 3** — publish the new config and run migrations:

```bash
php artisan vendor:publish --tag="laravel-audit-events-config"
php artisan migrate
```

The bundled `rename_model_audits_to_audit_events_table` migration renames `model_audits → audit_events` safely (checks table existence before acting). To keep the old table name, override the config:

```php
// config/audit-events.php
'table_name' => 'model_audits',
```

---

## Basic Usage

### Attach `IsAuditable` to a model

```php
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;

class Invoice extends Model
{
    use IsAuditable;
}
```

Every `created`, `updated`, `deleted`, and `restored` event now produces an audit record automatically.

---

## Querying Audit History

```php
$invoice->audits()->get();               // all audits for this model
$invoice->audits()->latest()->first();   // most recent audit

// Filtered by event type
$invoice->getCreatedHistory()->get();
$invoice->getUpdatedHistory()->get();
$invoice->getDeletedHistory()->get();
$invoice->getRestoredHistory()->get();
$invoice->getAuditHistory('invoice.sent')->get(); // any event name

// Diff between old and new values on an update audit
$audit = $invoice->getUpdatedHistory()->latest()->first();
$diff  = $audit->getDiff();
// ['amount' => ['old' => 100, 'new' => 250]]
```

### Query scopes on `ModelAudit`

For querying across the whole `audit_events` table (not just one model's `audits()`
relation), three local scopes are available:

```php
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

// Filter by event name (CRUD or semantic).
ModelAudit::whereEvent('asset.status_changed')->get();

// Filter by a key inside the JSON `context` column (portable across
// MySQL / PostgreSQL / SQLite via Laravel's `->` JSON path operator).
ModelAudit::whereContext('mission_id', 42)->get();

// Filter by the anchored model instance (uses the indexed morph columns).
ModelAudit::forAuditable($invoice)->get();

// Compose them freely.
ModelAudit::forAuditable($asset)
    ->whereEvent('asset.status_changed')
    ->whereContext('mission_id', 42)
    ->latest('created_at')
    ->get();
```

---

## Restore a Model

Roll back a model to the state stored in a previous audit's `old_values`:

```php
$audit = $invoice->audits()->latest()->first();
$audit->restore(); // forceFills old_values back onto the model and saves
```

Columns that no longer exist in the table are silently skipped.

---

## Attribute Masking

**Globally** in `config/audit-events.php`:

```php
'global_hidden' => [
    'password',
    'password_confirmation',
    'remember_token',
    'secret',
    'credit_card_number',
],
```

**Per model** — override `getHiddenForAudit()`:

```php
class Patient extends Model
{
    use IsAuditable;

    public function getHiddenForAudit(): array
    {
        return array_merge(parent::getHiddenForAudit(), [
            'ssn',
            'date_of_birth',
            'medical_record_number',
        ]);
    }
}
```

Masked attributes are stripped from both `old_values` and `new_values` before storage.

---

## AuditContext — Queue Jobs

In queue jobs, `Auth::id()` is `null` because there is no active session. Use `AuditContext::actingAs()` to inject the causer manually.

```php
use SoftArtisan\LaravelAuditEvents\AuditContext;

class ImportInvoicesJob implements ShouldQueue
{
    public function __construct(
        private readonly int $userId,
        private readonly string $batchId,
    ) {}

    public function handle(): void
    {
        AuditContext::actingAs($this->userId, [
            'source'   => 'import-job',
            'batch_id' => $this->batchId,
        ]);

        // All audits produced here will carry $this->userId as causer
        Invoice::create([...]);
        Invoice::find(42)->update([...]);

        AuditContext::reset(); // Always reset — prevents context bleed
    }
}
```

`AuditContext` is a static class. Reset is mandatory at the end of every job because PHP-FPM/Swoole workers reuse the same process.

---

## Free Events — ModelAudit::record()

Record semantic events that have no Eloquent model anchor:

```php
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

// Authentication events
ModelAudit::record('user.logged_in',  ['ip' => request()->ip()], $user->id);
ModelAudit::record('user.logged_out', [], $user->id);

// Bulk operations
ModelAudit::record('csv.exported', [
    'resource' => 'fixed-assets',
    'count'    => 1500,
    'tenant'   => tenant('id'),
], $this->userId);

// Report generation
ModelAudit::record('pdf.generated', [
    'template'   => 'annual-report',
    'invoice_id' => $invoice->id,
], auth()->id());
```

Signature:

```php
ModelAudit::record(
    string          $event,
    array           $context   = [],
    int|string|null $causerId  = null,
): ModelAudit
```

Free events are **never** filtered by the events whitelist.

---

## saveHistory() — Manual Model Events

Record a custom semantic event bound to a specific model instance:

```php
// Invoice sent to client
$invoice->saveHistory(
    event:     'invoice.sent',
    oldValues: [],
    newValues: [],
    context:   ['recipient' => 'client@example.com', 'channel' => 'email'],
);

// Status transition with diff
$invoice->saveHistory(
    event:     'invoice.approved',
    oldValues: ['status' => 'draft'],
    newValues: ['status' => 'approved'],
    context:   ['approver_id' => auth()->id()],
);
```

Signature:

```php
public function saveHistory(
    string $event,
    array  $oldValues = [],
    array  $newValues = [],
    array  $context   = [],
): void
```

Not subject to the events whitelist.

---

## TracksRelationChanges

Laravel does not emit Eloquent events when pivot tables are modified (`sync`, `attach`, `detach`). Use this trait alongside `IsAuditable` to track those changes manually.

```php
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;
use SoftArtisan\LaravelAuditEvents\Concerns\TracksRelationChanges;

class Role extends Model
{
    use IsAuditable, TracksRelationChanges;
}
```

```php
class RoleService
{
    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $before = $role->permissions->pluck('name')->all();

        $role->permissions()->sync($permissionIds);

        $after = $role->fresh()->permissions->pluck('name')->all();

        $role->recordRelationAudit('permissions', $before, $after, [
            'actor_id' => auth()->id(),
        ]);
    }
}
```

The audit record event will be `relation.synced`. The `old_values` and `new_values` are keyed by the relation name:

```json
{
  "old_values": { "permissions": ["read", "write"] },
  "new_values": { "permissions": ["read", "write", "delete"] }
}
```

---

## Deep JSON Diff

When a model has a JSON/array column, `getDiff()` recursively diffs it to pinpoint exact sub-key changes:

```php
// Before: settings = ['theme' => 'light', 'notifications' => ['email' => true, 'sms' => false]]
// After:  settings = ['theme' => 'dark',  'notifications' => ['email' => true, 'sms' => true]]

$diff = $audit->getDiff();
// [
//   'settings' => [
//     'old'  => ['theme' => 'light', 'notifications' => ['email' => true, 'sms' => false]],
//     'new'  => ['theme' => 'dark',  'notifications' => ['email' => true, 'sms' => true]],
//     'diff' => [
//       'theme'         => ['old' => 'light', 'new' => 'dark'],
//       'notifications' => [
//         'old'  => ['email' => true, 'sms' => false],
//         'new'  => ['email' => true, 'sms' => true],
//         'diff' => ['sms' => ['old' => false, 'new' => true]],
//       ],
//     ],
//   ],
// ]
```

Configure in `config/audit-events.php`:

```php
'json_diff' => [
    'enabled'   => true,
    'max_depth' => 3,
],
```

---

## Cryptographic Integrity

The integrity feature adds a tamper-evident HMAC-SHA256 signature to every audit record, plus a hash chain that links each record to its predecessor within the same model's history.

### Setup

**Step 1** — run the migration:

```bash
php artisan migrate
# Applies: add_signature_to_audit_events_table
```

**Step 2** — enable in `config/audit-events.php`:

```php
'integrity' => [
    'enabled'   => true,
    'key'       => null,       // null uses APP_KEY. Set a dedicated AUDIT_SIGNING_KEY for isolation.
    'algorithm' => 'sha256',   // Any PHP hash_hmac() algorithm
],
```

**Step 3** (optional) — set a dedicated signing key in `.env`:

```env
AUDIT_SIGNING_KEY=base64:your-32-byte-key-here
```

Then reference it in the published config:

```php
'integrity' => [
    'enabled' => true,
    'key'     => env('AUDIT_SIGNING_KEY'),
],
```

### How it works

Each new audit record receives:

- **`signature`** (varchar 64): HMAC over a canonical JSON payload covering `auditable_type`, `auditable_id`, `event`, `user_id`, `old_values`, `new_values`, `context`, `created_at`, and `previous_hash`.
- **`previous_hash`** (varchar 64): the `signature` of the immediately preceding record for the same `(auditable_type, auditable_id)` pair. `null` for the first record.

The hash chain scope is per model instance — two different `Invoice` records maintain independent chains, avoiding write contention. Free-standing events (no auditable) are chained by `user_id`.

### Verifying a single record

```php
$audit = Invoice::find(1)->audits()->latest()->first();

$audit->isSigned();       // true if the record has a non-null signature
$audit->verifySignature(); // true if the HMAC matches; false if tampered
```

### Bulk verification

```bash
php artisan audit-events:verify

# Limit to a model
php artisan audit-events:verify --model="App\Models\Invoice"

# Limit to one instance
php artisan audit-events:verify --model="App\Models\Invoice" --id=42

# Date range
php artisan audit-events:verify --from=2025-01-01 --until=2025-12-31

# Stop on first failure
php artisan audit-events:verify --fail-fast
```

Exit codes: `0` = all signed records passed · `1` = tampered records found (or integrity disabled).

Records created before the feature was enabled are reported as **unsigned** (not tampered) and do not affect the exit code.

### Key management

- Use a **dedicated key** (`AUDIT_SIGNING_KEY`) separate from `APP_KEY` so that rotating your app key does not invalidate existing audit signatures.
- Store the key in a secrets manager (AWS Secrets Manager, HashiCorp Vault). Do not store it only in `.env` for compliance-critical applications.
- If you must rotate the signing key, re-sign historical records via a one-off artisan command (not provided — implement in your app with `AuditSignatureService`).

---

## Cold Archiving

Archiving moves records older than a configurable threshold to cold storage, preserving them for legal/compliance purposes while keeping the hot `audit_events` table lean.

### Setup

**Step 1** — run the migration (database driver):

```bash
php artisan migrate
# Applies: create_audit_events_archive_table
```

**Step 2** — enable in `config/audit-events.php`:

```php
'archive' => [
    'enabled'            => true,
    'archive_after_days' => 90,         // Records older than 90 days
    'driver'             => 'database', // 'database' | 'json_file'
    'table_name'         => 'audit_events_archive',
    'path'               => null,       // Required for json_file driver; null = storage_path('audit-archives')
],
```

**Step 3** — schedule the archive command:

```php
// bootstrap/app.php
use Illuminate\Console\Scheduling\Schedule;

->withSchedule(function (Schedule $schedule) {
    $schedule->command('audit-events:archive')->weekly();
})
```

### Database driver

Moves records in transactional batches (default 500/batch). Each batch:
1. Bulk-inserts into `audit_events_archive` (with `archived_at` timestamp)
2. Deletes from `audit_events` — only if the insert succeeded

The archive table has an identical schema to `audit_events`, plus `archived_at`. Signatures and hash chain columns (`signature`, `previous_hash`) are preserved.

### JSON file driver

Appends records to daily JSONL files (one JSON object per line):

```
storage/audit-archives/audit_events_archive_2025_03_29.jsonl
storage/audit-archives/audit_events_archive_2025_03_30.jsonl
```

Each line is a complete JSON representation of the audit record plus `archived_at`. Files can be gzipped and uploaded to S3 for long-term storage.

```php
'archive' => [
    'enabled' => true,
    'driver'  => 'json_file',
    'path'    => storage_path('audit-archives'), // or /mnt/cold-storage
],
```

### Archive command options

```bash
# Preview without changes
php artisan audit-events:archive --dry-run

# Override threshold
php artisan audit-events:archive --days=365

# Override driver
php artisan audit-events:archive --driver=json_file

# Limit to one model type
php artisan audit-events:archive --model="App\Models\Invoice"

# Custom batch size
php artisan audit-events:archive --chunk=1000
```

### Hash chain continuity after archiving

After archiving, the live table has a gap at the chain boundary. The **next** new audit record on the same model will reference the most recent *remaining live* record's signature as its `previous_hash`. The archived record retains its signature intact and can be cross-referenced manually.

When running `audit-events:verify`, a chain break at an archive boundary is expected. The verify command reports it as a gap rather than tampering.

---

## Pruning

Pruning **deletes** records permanently. Use it for data that has no legal retention obligation.

```php
'pruning' => [
    'enabled'      => true,
    'keep_for_days' => 365,
],
```

When `enabled` is `true`, the service provider auto-schedules `model:prune` daily. You can also schedule it manually:

```php
Schedule::command('model:prune', [
    '--model' => [\SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class],
])->daily();
```

### Multi-tenant retention

`keep_for_days` is read dynamically at every pruning run (never cached), so multi-tenant applications can set different retention periods per tenant:

```php
// Tenant A — standard
config(['audit-events.pruning.keep_for_days' => 365]);

// Tenant B — financial compliance (7 years)
config(['audit-events.pruning.keep_for_days' => 2555]);
```

### Pruning vs. Archiving

| | Pruning | Archiving |
|---|---|---|
| Records after operation | Deleted permanently | Preserved in cold storage |
| Compliance-safe | Only if retention period met | Yes |
| Hash chain | Broken at deletion | Intact in archive |
| Recommended for | Non-sensitive operational data | Financial, medical, legal records |

Use **pruning** for high-volume, low-sensitivity events. Use **archiving** when records must be retained for legal or compliance reasons.

---

## Artisan Commands

### `audit-events:stats`

Display audit event statistics.

```bash
php artisan audit-events:stats
```

Output includes: total records, breakdown by event type, top 5 audited model classes, date range, table size (MySQL/PostgreSQL), and archive stats when `archive.enabled = true`.

---

### `audit-events:verify`

Verify the cryptographic integrity of audit records. Requires `integrity.enabled = true`.

```bash
php artisan audit-events:verify [options]

Options:
  --model=CLASS    Limit to a specific auditable_type (FQCN)
  --id=ID          Limit to a specific auditable_id (requires --model)
  --from=DATE      Verify records created on or after this date (Y-m-d)
  --until=DATE     Verify records created on or before this date (Y-m-d)
  --fail-fast      Stop at the first failure
```

Exit codes: `0` = pass · `1` = tampered records or feature disabled.

---

### `audit-events:archive`

Move old audit records to cold storage.

```bash
php artisan audit-events:archive [options]

Options:
  --days=N         Archive records older than N days (overrides config)
  --driver=NAME    Use 'database' or 'json_file' (overrides config)
  --dry-run        Show what would be archived without moving records
  --chunk=N        Records per batch (default: 500)
  --model=CLASS    Limit to a specific auditable_type (FQCN)
```

---

### `audit-events:stats` (archive section)

When `archive.enabled = true`, the stats command adds an archive section:

```
Archive
  Archived records : 18 432
  Oldest archived  : 2024-01-03 09:12:00
  Newest archived  : 2025-12-31 23:59:00
```

---

## Configuration Reference

```php
// config/audit-events.php

return [

    // ── Database ──────────────────────────────────────────────────────────────

    'table_name'  => 'audit_events',
    'model_class' => \SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class,

    // ── Column Mapping ────────────────────────────────────────────────────────
    //
    // morph_type options: 'string' (recommended), 'integer', 'uuid', 'ulid'

    'table_fields' => [
        'id'           => 'audit_id',
        'user_id'      => 'user_id',
        'event'        => 'event',
        'morph_prefix' => 'auditable',
        'morph_type'   => 'string',
        'url'          => 'url',
        'ip_address'   => 'ip_address',
        'user_agent'   => 'user_agent',
        'old_values'   => 'old_values',
        'new_values'   => 'new_values',
        'context'      => 'context',
    ],

    // ── Behaviour ─────────────────────────────────────────────────────────────

    'audit_on_create'  => true,
    'audit_on_update'  => true,

    // true  → remove all audits when a model is hard-deleted
    // false → keep audits and record a final "deleted" entry
    'remove_on_delete' => true,

    // Automatic Eloquent events whitelist.
    // saveHistory() and ModelAudit::record() always bypass this list.
    'events' => ['created', 'updated', 'deleted', 'restored'],

    // ── Security ──────────────────────────────────────────────────────────────

    'global_hidden' => [
        'password',
        'password_confirmation',
        'remember_token',
        'secret',
        'credit_card_number',
    ],

    // ── Deep JSON Diff ────────────────────────────────────────────────────────

    'json_diff' => [
        'enabled'   => true,
        'max_depth' => 3,
    ],

    // ── User Resolver ─────────────────────────────────────────────────────────

    'user' => [
        'guards'   => ['web', 'api', 'sanctum'],
        'resolver' => null, // callable — return the authenticated user instance
    ],

    // ── Pruning ───────────────────────────────────────────────────────────────

    'pruning' => [
        'enabled'       => false,
        'keep_for_days' => 365,
    ],

    // ── Cryptographic Integrity ───────────────────────────────────────────────

    'integrity' => [
        'enabled'   => false,
        'key'       => null,       // null falls back to APP_KEY
        'algorithm' => 'sha256',
    ],

    // ── Archiving ─────────────────────────────────────────────────────────────

    'archive' => [
        'enabled'            => false,
        'archive_after_days' => 90,
        'driver'             => 'database', // 'database' | 'json_file'
        'table_name'         => 'audit_events_archive',
        'path'               => null,       // null → storage_path('audit-archives')
    ],
];
```

---

## Testing

```bash
composer test
```

Or directly with Pest:

```bash
./vendor/bin/pest
./vendor/bin/pest --coverage
```

Static analysis:

```bash
./vendor/bin/phpstan analyse --configuration phpstan.neon.dist --memory-limit=512M
```

### Testing in your application

Disable integrity in tests to avoid `APP_KEY` dependency:

```php
// tests/TestCase.php
protected function defineEnvironment($app): void
{
    $app['config']->set('audit-events.integrity.enabled', false);
}
```

Or enable it with a known key:

```php
$app['config']->set('audit-events.integrity.enabled', true);
$app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
