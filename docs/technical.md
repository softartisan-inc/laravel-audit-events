# Technical Documentation — Laravel Audit Events

> Package: `softartisan/laravel-audit-events` v2.1
> PHP 8.4 · Laravel 12 · PHPStan level 5

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Package Structure](#2-package-structure)
3. [Database Schema](#3-database-schema)
4. [IsAuditable Trait — Deep Dive](#4-isauditable-trait--deep-dive)
5. [AuditContext](#5-auditcontext)
6. [ModelAudit Model](#6-modelaudit-model)
7. [Free Events — ModelAudit::record()](#7-free-events--modelauditrecord)
8. [TracksRelationChanges](#8-tracksrelationchanges)
9. [Deep JSON Diff](#9-deep-json-diff)
10. [Cryptographic Integrity](#10-cryptographic-integrity)
11. [Cold Archiving](#11-cold-archiving)
12. [Pruning](#12-pruning)
13. [Artisan Commands — Full Reference](#13-artisan-commands--full-reference)
14. [Configuration — Full Reference](#14-configuration--full-reference)
15. [User Resolution](#15-user-resolution)
16. [MCP Integration](#16-mcp-integration)
17. [Multi-Tenancy Patterns](#17-multi-tenancy-patterns)
18. [Performance Considerations](#18-performance-considerations)
19. [Security Considerations](#19-security-considerations)
20. [Testing Guide](#20-testing-guide)
21. [Migration Guide — v1.x to v2.x](#21-migration-guide--v1x-to-v2x)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Your Eloquent Models                         │
│                   (use IsAuditable, TracksRelationChanges)           │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ Eloquent events
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         IsAuditable trait                           │
│  bootIsAuditable() → created / updated / deleted / restored hooks   │
│  persistAudit()   → builds $data, injects signature if enabled      │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
          ┌──────────────────────┼────────────────────┐
          │                    │                      │
          ▼                    ▼                      ▼
   AuditContext          AuditSignatureService    ModelAudit::record()
   (causer injection)    (HMAC + hash chain)      (free events)
          │                    │                      │
          └────────────────────┼──────────────────────┘
                               │ ->create($data)
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    audit_events table (hot)                         │
│  audit_id | auditable_type | auditable_id | event | user_id         │
│  old_values | new_values | context | signature | previous_hash      │
│  url | ip_address | user_agent | created_at | updated_at            │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ audit-events:archive
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                  audit_events_archive table (cold)                  │
│  (identical schema + archived_at)                                   │
│  or JSONL files: audit_events_archive_YYYY_MM_DD.jsonl              │
└─────────────────────────────────────────────────────────────────────┘
```

**Key design decisions:**

- **Single write path**: `persistAudit()` is the only method that writes to the audit table for model-bound events. This ensures signature injection is always applied when enabled.
- **Opt-in features**: Both integrity and archiving default to `false`. Enabling them requires a migration run first.
- **Static context**: `AuditContext` uses PHP static properties, which is safe in synchronous environments. In async environments (Swoole, RoadRunner), use the singleton binding via the DI container.
- **Configurable columns**: Every column name is configurable, allowing the package to integrate into existing database schemas without conflict.
- **Scoped hash chain**: The chain is per `(auditable_type, auditable_id)` rather than global to avoid write serialization under concurrent load.

---

## 2. Package Structure

```
src/
├── AuditContext.php                    Static causer injection for queue jobs
├── LaravelAuditEvents.php             Placeholder class (facade target)
├── LaravelAuditEventsServiceProvider.php
│
├── Archive/
│   ├── Contracts/
│   │   └── ArchiveDriver.php          Interface: archive(Collection): int
│   └── Drivers/
│       ├── DatabaseArchiveDriver.php  Bulk-insert to archive table
│       └── JsonFileArchiveDriver.php  Append to JSONL daily files
│
├── Commands/
│   ├── AuditEventsStatsCommand.php    audit-events:stats
│   ├── AuditEventsVerifyCommand.php   audit-events:verify
│   └── AuditEventsArchiveCommand.php  audit-events:archive
│
├── Concerns/
│   ├── IsAuditable.php               Core trait — model lifecycle auditing
│   └── TracksRelationChanges.php     Pivot/sync relation tracking
│
├── Facades/
│   └── LaravelAuditEvents.php
│
├── Mcp/
│   ├── Prompts/AuditAnalysisPrompt.php
│   ├── Servers/AuditEventsServer.php
│   └── Tools/AuditHistoryTool.php
│
├── Models/
│   └── ModelAudit.php                Eloquent model for audit_events
│
└── Services/
    └── AuditSignatureService.php     HMAC computation + hash chain

config/
└── audit-events.php

database/
├── factories/
│   └── ModelAuditFactory.php
└── migrations/
    ├── create_audit_events_table.php
    ├── rename_model_audits_to_audit_events_table.php
    ├── add_context_to_audit_events_table.php
    ├── add_signature_to_audit_events_table.php
    └── create_audit_events_archive_table.php
```

---

## 3. Database Schema

### `audit_events` table (hot)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `audit_id` | bigint unsigned, PK | No | Configurable via `table_fields.id` |
| `auditable_type` | varchar(255) | Yes | Model FQCN |
| `auditable_id` | varchar(64) | Yes | Supports int, UUID, ULID |
| `event` | varchar(255) | Yes | `created`, `updated`, `deleted`, `restored`, or custom |
| `user_id` | bigint unsigned | Yes | Acting user |
| `url` | text | Yes | HTTP request URL |
| `ip_address` | varchar(45) | Yes | IPv4 or IPv6 |
| `user_agent` | text | Yes | Browser/client UA string |
| `old_values` | json | Yes | Previous attribute values (after masking) |
| `new_values` | json | Yes | New attribute values (after masking) |
| `context` | json | Yes | Arbitrary payload |
| `signature` | varchar(64) | Yes | HMAC-SHA256 hex digest |
| `previous_hash` | varchar(64) | Yes | Signature of the preceding record in the chain |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

**Index**: `(auditable_type, auditable_id)` — composite index for efficient per-model queries.

**Column names** are fully configurable. See `table_fields` in the configuration.

**`auditable_id` type** depends on `morph_type`:
- `string` (default): `varchar(64)` — recommended; supports all ID types
- `integer`: `bigint unsigned`
- `uuid`: `char(36)`
- `ulid`: `char(26)`

### `audit_events_archive` table (cold)

Identical schema to `audit_events` plus:

| Column | Type | Notes |
|---|---|---|
| `archived_at` | timestamp | When the record was moved to cold storage |

Additional index: `archived_at`.

---

## 4. IsAuditable Trait — Deep Dive

### Boot sequence

`bootIsAuditable()` registers four Eloquent event listeners:

```
Model::creating → (before save)
Model::created  → IsAuditable records "created" audit ← HERE
Model::updating → (before save)
Model::updated  → IsAuditable records "updated" audit ← HERE
Model::deleting → (before deletion)
Model::deleted  → IsAuditable records "deleted" audit ← HERE
Model::restoring→ (before restore)
Model::restored → IsAuditable records "restored" audit ← (not registered yet — see note)
```

> **Note on restored**: If you use `SoftDeletes`, the `restored` event is dispatched after `restore()`. Register it manually or use `saveHistory('restored')` if needed.

### `updated` listener — cast-aware diff

The `updated` listener uses `$model->getChanges()` to find changed columns, then calls:
- `$model->getOriginal($key)` for old values (already cast-aware in Laravel)
- `$model->getAttribute($key)` for new values (explicitly cast-aware)

This ensures JSON/array columns are stored as PHP arrays rather than raw JSON strings in `old_values`/`new_values`.

**Only-timestamps filter**: If the only change is `updated_at`, the audit is skipped to avoid noise from `touch()` calls.

### `deleted` listener — hard vs soft delete

```php
static::deleted(function (Model $model): void {
    if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
        // Soft delete → always record (remove_on_delete ignored)
        $model->recordEloquentAudit('deleted', $model->getAttributes(), []);
        return;
    }
    // Hard delete
    if (config('audit-events.remove_on_delete', false)) {
        $model->audits()->delete(); // Remove all audits
    } else {
        $model->recordEloquentAudit('deleted', $model->getAttributes(), []);
    }
});
```

### `persistAudit()` — single write path

```php
private function persistAudit(
    string $event,
    array  $oldValues,
    array  $newValues,
    array  $context = []
): void
```

Sequence:
1. Strip masked attributes from `$oldValues` and `$newValues`
2. Resolve `$userId` from `AuditContext::getCauserId() ?? Auth::id()`
3. Build `$data` array with all audit columns
4. Attempt to inject `url`, `ip_address`, `user_agent` (silently skipped outside HTTP context)
5. **If `integrity.enabled`**: call `AuditSignatureService::getPreviousHash()`, build payload, compute HMAC, add `signature` and `previous_hash` to `$data`
6. `$this->audits()->create($data)` — polymorphic relation creates the record

### Public API

| Method | Description |
|---|---|
| `audits()` | MorphMany relation to ModelAudit |
| `getAuditHistory(?string $event)` | Filtered MorphMany (chainable) |
| `getCreatedHistory()` | `getAuditHistory('created')` |
| `getUpdatedHistory()` | `getAuditHistory('updated')` |
| `getDeletedHistory()` | `getAuditHistory('deleted')` |
| `getRestoredHistory()` | `getAuditHistory('restored')` |
| `saveHistory(event, old, new, context)` | Record a free event on this model |
| `getHiddenForAudit()` | Return merged hidden attributes list |

---

## 5. AuditContext

### Purpose

`AuditContext` is a static holder for the causer ID in contexts where `Auth::id()` is `null` — queue jobs, console commands, event listeners, and scheduled tasks.

### API

```php
AuditContext::actingAs(int|string $userId, array $extra = []): void
AuditContext::reset(): void
AuditContext::getCauserId(): int|string|null
AuditContext::getExtra(): array
```

### Usage pattern

```php
class ProcessPayrollJob implements ShouldQueue
{
    public function handle(): void
    {
        AuditContext::actingAs($this->adminUserId, [
            'job_id'    => $this->job->getJobId(),
            'tenant_id' => $this->tenantId,
        ]);

        try {
            // All model mutations here will carry adminUserId
            foreach ($this->employees as $employee) {
                $employee->update(['salary' => $this->computeSalary($employee)]);
            }
        } finally {
            AuditContext::reset(); // Always reset — even on exception
        }
    }
}
```

### Priority order for causer resolution

1. `AuditContext::getCauserId()` — manual injection (highest priority)
2. `Auth::id()` — active session/guard
3. `null` — no causer recorded

### Caution with async workers

In Swoole or RoadRunner, multiple requests share the same PHP process. If a job sets `AuditContext` and another coroutine runs concurrently, context can bleed. Solutions:
- Always call `AuditContext::reset()` in a `finally` block
- For full isolation, bind `AuditContext` per-coroutine using a custom DI binding

---

## 6. ModelAudit Model

### Class: `SoftArtisan\LaravelAuditEvents\Models\ModelAudit`

Extends `Illuminate\Database\Eloquent\Model`. Uses `HasFactory` and `Prunable`.

### Constructor behaviour

`__construct()` sets `$this->table` and `$this->primaryKey` from config at instantiation time. `$this->fillable` is built dynamically from `table_fields`, including `signature` and `previous_hash`.

`getCasts()` is overridden to provide JSON casts for `old_values`, `new_values`, and `context` before the constructor body runs — this fixes the "Array to string conversion" error when a factory passes array values at construction time.

### Relations

```php
// Polymorphic parent model
$audit->auditable; // → the Invoice, User, etc.

// Acting user
$audit->user; // → App\Models\User (resolved dynamically)
```

### Instance methods

```php
// Roll back the auditable model to old_values
$audit->restore(): ?Model

// Compute a structured diff between old_values and new_values
$audit->getDiff(): array

// Integrity
$audit->isSigned(): bool
$audit->verifySignature(): bool  // throws RuntimeException if integrity.enabled = false
```

### Static methods

```php
// Record a free-standing event (no auditable)
ModelAudit::record(
    string          $event,
    array           $context   = [],
    int|string|null $causerId  = null,
): ModelAudit
```

### Prunable scope

```php
public function prunable(): Builder
{
    $days = (int) config('audit-events.pruning.keep_for_days', 365);
    return static::where($this->getCreatedAtColumn(), '<', now()->subDays($days));
}
```

The value is read dynamically at every invocation, enabling per-tenant overrides.

---

## 7. Free Events — ModelAudit::record()

### When to use

Use `ModelAudit::record()` when there is no Eloquent model to bind the event to:

- Authentication (login, logout, 2FA verify, password reset)
- Bulk operations (CSV import, report export, mass update)
- Manual administrative actions (tenant suspension, billing overrides)
- Business process events (order confirmed, shipment dispatched)

### Signature

```php
public static function record(
    string          $event,
    array           $context   = [],
    int|string|null $causerId  = null,
): ModelAudit
```

### Behaviour

- `auditable_type` and `auditable_id` are `null`
- `old_values` and `new_values` are empty arrays
- `causerId` overrides `AuditContext::getCauserId()` then `Auth::id()`
- If `integrity.enabled = true`, the record is signed and chained using the scope `(null, null)` — free events form their own chain

### Examples

```php
// Login event
ModelAudit::record('user.logged_in', [
    'ip'         => request()->ip(),
    'user_agent' => request()->userAgent(),
    '2fa'        => $user->two_factor_enabled,
], $user->id);

// Failed login
ModelAudit::record('user.login_failed', [
    'email'    => $request->email,
    'attempts' => $this->limiter->attempts($key),
]);

// Large data export
ModelAudit::record('report.exported', [
    'format'     => 'xlsx',
    'rows'       => 48_302,
    'filter'     => $request->all(),
    'duration_ms'=> $timer->elapsed(),
], auth()->id());
```

---

## 8. TracksRelationChanges

### Purpose

Laravel does not fire Eloquent `updated` events for pivot table modifications (`sync`, `attach`, `detach`, `syncWithoutDetaching`). `TracksRelationChanges` provides a manual API to record these changes as `relation.synced` audit events.

### Requirements

The model must also use `IsAuditable` (the trait calls `$this->audits()->create()`).

### Method

```php
public function recordRelationAudit(
    string $relation,  // Relation name: "permissions", "roles", "tags"
    array  $before,    // State before the sync
    array  $after,     // State after the sync
    array  $context = []
): void
```

The event name is always `relation.synced`. `old_values` and `new_values` are keyed by the relation name:

```json
{
  "old_values": { "permissions": ["read"] },
  "new_values": { "permissions": ["read", "write", "delete"] }
}
```

### Complete pattern

```php
class PermissionService
{
    public function syncForRole(Role $role, array $permissionIds): void
    {
        // 1. Capture current state
        $before = $role->permissions()->pluck('name')->all();

        // 2. Perform the pivot operation
        $role->permissions()->sync($permissionIds);

        // 3. Capture new state
        $after = $role->fresh()->permissions()->pluck('name')->all();

        // 4. Record the audit
        $role->recordRelationAudit('permissions', $before, $after, [
            'actor_id'   => auth()->id(),
            'via'        => 'admin-panel',
        ]);
    }
}
```

---

## 9. Deep JSON Diff

### How it works

`ModelAudit::getDiff()` computes a diff map between `old_values` and `new_values`. For keys whose values are arrays (JSON columns), it recursively computes a sub-diff up to `json_diff.max_depth` levels.

### Output structure

Scalar field:
```php
['amount' => ['old' => 100.00, 'new' => 250.00]]
```

Array/JSON field:
```php
[
  'settings' => [
    'old'  => ['theme' => 'light', 'locale' => 'en'],
    'new'  => ['theme' => 'dark',  'locale' => 'en'],
    'diff' => [
      'theme' => ['old' => 'light', 'new' => 'dark'],
    ],
  ],
]
```

### Depth example

With `max_depth = 3` and 4-level nesting:

```
Level 1 → diffed (result has 'diff' key)
Level 2 → diffed
Level 3 → diffed
Level 4 → NOT diffed (max_depth reached — returns [] for sub-diff)
```

### Configuration

```php
'json_diff' => [
    'enabled'   => true,   // false → getDiff() returns flat diff only
    'max_depth' => 3,
],
```

### Performance note

Deep diff is computed on-demand when `getDiff()` is called — never stored. It is purely a read-side feature.

---

## 10. Cryptographic Integrity

### Goal

Detect post-hoc tampering with audit records by any actor with direct database access (DBAs, compromised admin accounts, database restore from attacker-controlled backup).

### Mechanism

#### Per-row HMAC

Each audit record's `signature` is an HMAC-SHA256 over a **canonical payload** — a JSON-encoded object with fields in a fixed order:

```json
{
  "auditable_type": "App\\Models\\Invoice",
  "auditable_id":   "42",
  "event":          "updated",
  "user_id":        "7",
  "old_values":     "{\"amount\":100}",
  "new_values":     "{\"amount\":250}",
  "context":        "null",
  "created_at":     "2025-03-29T14:00:00+00:00",
  "previous_hash":  "a3f1..."
}
```

The fixed key order prevents field-reordering attacks. Array fields are JSON-encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` for consistent encoding.

#### Hash chain

Each record's canonical payload includes `previous_hash` — the `signature` of the immediately preceding record for the same `(auditable_type, auditable_id)` pair. This creates a chain:

```
Record 1: previous_hash = null,     signature = H(payload₁)
Record 2: previous_hash = H(payload₁), signature = H(payload₂ ∥ H(payload₁))
Record 3: previous_hash = H(payload₂), signature = H(payload₃ ∥ H(payload₂))
```

Deleting record 2 breaks the chain: record 3's `previous_hash` no longer matches any existing signature. Inserting a fake record 2' also breaks it unless the attacker recomputes all subsequent signatures — which requires the signing key.

#### Chain scope

The chain is **per `(auditable_type, auditable_id)`**, not global. This allows concurrent writes for different models without write serialization. Free-standing events (null auditable) form their own chain.

### AuditSignatureService

```php
class AuditSignatureService
{
    // Compute the HMAC for a payload
    public function computeSignature(array $payload, string $key, string $algorithm = 'sha256'): string;

    // Verify stored signature matches recomputed one (constant-time comparison)
    public function verifySignature(string $storedSignature, array $payload, string $key, string $algorithm): bool;

    // Get the signature of the most recent signed record for the scope
    public function getPreviousHash(string|null $type, string|int|null $id, string $tableName): ?string;
}
```

`AuditSignatureService` has no Eloquent dependency. It can be used standalone or injected via DI.

### APP_KEY decoding

Laravel's `APP_KEY` is base64-encoded (`base64:...`). `AuditSignatureService` automatically decodes it:

```php
private function resolveKey(string $key): string
{
    if (str_starts_with($key, 'base64:')) {
        return base64_decode(substr($key, 7));
    }
    return $key;
}
```

Raw keys (32+ bytes of entropy) are used as-is.

### Enable integrity

**1.** Run the migration:
```bash
php artisan migrate
```

**2.** Set in config:
```php
'integrity' => [
    'enabled'   => true,
    'key'       => null,       // Uses APP_KEY
    'algorithm' => 'sha256',
],
```

**3.** Optionally use a dedicated key:
```env
AUDIT_SIGNING_KEY=base64:GENERATED_KEY_HERE
```

```php
'key' => env('AUDIT_SIGNING_KEY'),
```

### Verifying integrity

```bash
# All records
php artisan audit-events:verify

# One model
php artisan audit-events:verify --model="App\Models\Invoice"

# One instance
php artisan audit-events:verify --model="App\Models\Invoice" --id=42

# Date range
php artisan audit-events:verify --from=2025-01-01 --until=2025-12-31

# Stop on first failure (CI use case)
php artisan audit-events:verify --fail-fast
```

**Output categories:**
- `Verified` — signed and HMAC matches
- `Unsigned` — no signature (created before feature was enabled). Does not fail.
- `Tampered` — signed but HMAC does not match. Causes exit code 1.

### Limitations

| Threat | Covered? | Notes |
|---|---|---|
| Direct DB UPDATE on a row | Yes | HMAC will no longer match |
| Row deletion | Partial | Detected via chain break on neighbouring records |
| Row insertion | Partial | Detected via chain break on existing signatures |
| Row reordering | Yes | `previous_hash` chain breaks |
| Key theft + full re-sign | No | Requires access to the signing key AND all records |
| Side-channel on key | No | Mitigate by using a hardware security module |

---

## 11. Cold Archiving

### Purpose

Keep audit records indefinitely for legal/compliance reasons without the hot `audit_events` table growing unbounded.

### DatabaseArchiveDriver

**Algorithm (per chunk):**
1. Begin DB transaction
2. `INSERT INTO audit_events_archive SELECT ... FROM audit_events WHERE id IN (...)`
3. `DELETE FROM audit_events WHERE id IN (...)`
4. Commit — if step 2 fails, step 3 is never reached

The archive row is identical to the live row, plus `archived_at = NOW()`.

**Schema note:** `audit_events_archive.audit_id` is the original primary key. There is no surrogate PK — the original ID is preserved for cross-referencing.

### JsonFileArchiveDriver

**Algorithm (per chunk):**
1. Append JSONL lines to `{path}/audit_events_archive_{YYYY_MM_DD}.jsonl`
2. Delete from `audit_events`

Step 2 runs after step 1 — if the write fails, no records are deleted (data safety over atomicity). If the delete fails after a successful write, duplicate data exists in the file but no data is lost.

**File format (one line per record):**
```json
{"audit_id":1,"auditable_type":"App\\Models\\Invoice","auditable_id":"42","event":"updated","user_id":7,"url":"https://...","ip_address":"1.2.3.4","user_agent":"Mozilla...","old_values":{"amount":100},"new_values":{"amount":250},"context":null,"signature":"a3f1...","previous_hash":null,"created_at":"2025-01-01T00:00:00.000000Z","updated_at":"2025-01-01T00:00:00.000000Z","archived_at":"2025-03-29T15:00:00+00:00"}
```

### Custom ArchiveDriver

Implement `SoftArtisan\LaravelAuditEvents\Archive\Contracts\ArchiveDriver`:

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use SoftArtisan\LaravelAuditEvents\Archive\Contracts\ArchiveDriver;

class S3ArchiveDriver implements ArchiveDriver
{
    public function archive(Collection $records): int
    {
        // Upload to S3, send to external log aggregator, etc.
        $count = 0;
        foreach ($records as $record) {
            $this->s3->putObject([
                'Bucket' => 'my-audit-archive',
                'Key'    => "audits/{$record->getKey()}.json",
                'Body'   => json_encode($record->toArray()),
            ]);
            $count++;
        }
        return $count;
    }
}
```

Then resolve it in a custom `AuditEventsArchiveCommand` subclass, or use a service provider binding.

### Hash chain after archiving

When a record is archived, it is removed from `audit_events`. The chain for that model now has a gap. The next new audit record will reference the last *remaining live* record's signature as `previous_hash`.

**What this means for `audit-events:verify`:**

- Records in the live table are verified against each other.
- A chain break is detected where the archived record used to be.
- The verify command reports this as a "chain gap — check archive" rather than tampering when the `database` driver is used (it can query `audit_events_archive` for the missing hash).
- For the `json_file` driver, the break is reported as "chain break — predecessor is in archive files".

**Best practice:** Run `audit-events:verify` before running `audit-events:archive` to ensure the records being archived are already valid.

---

## 12. Pruning

`ModelAudit` implements Laravel's `Prunable` interface. The `prunable()` scope:

```php
public function prunable(): Builder
{
    $days = (int) config('audit-events.pruning.keep_for_days', 365);
    return static::where($this->getCreatedAtColumn(), '<', now()->subDays($days));
}
```

### Auto-scheduling

When `pruning.enabled = true`, the service provider registers:

```php
$schedule->command('model:prune', ['--model' => [$modelClass]])->daily();
```

### Manual scheduling

```php
// bootstrap/app.php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('model:prune', [
        '--model' => [\SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class],
    ])->daily()->at('02:00');
})
```

### Pruning vs Archiving decision

- **Prune** when records are not legally required after the retention period
- **Archive** when records must be preserved (financial, healthcare, GDPR right to audit)
- **Both** can coexist: archive at 90 days (cold), prune the archive at 7 years

---

## 13. Artisan Commands — Full Reference

### `audit-events:stats`

```
Description:
  Display audit events statistics (totals, event breakdown, top models, table size, date range)

Usage:
  audit-events:stats

Output sections:
  Total audit events
  Events breakdown       (table: event | count)
  Top 5 most audited models (table: model | count)
  Date range             (oldest → newest created_at)
  Table size             (MySQL/PostgreSQL only)
  Archive                (only when archive.enabled = true)
```

Exit codes: `0` always.

---

### `audit-events:verify`

```
Description:
  Verify the cryptographic integrity of audit event records

Usage:
  audit-events:verify [options]

Options:
  --model=CLASS    Limit to a specific auditable_type (FQCN, e.g. "App\Models\Invoice")
  --id=ID          Limit to a specific auditable_id. Requires --model.
  --from=DATE      Verify records created on or after this date (Y-m-d)
  --until=DATE     Verify records created on or before this date (Y-m-d)
  --fail-fast      Stop at the first tampered record

Exit codes:
  0   All signed records passed verification
  1   Tampered records found, OR integrity.enabled = false

Processing:
  Records are fetched in chunks of 500 to avoid memory exhaustion.
  Unsigned records (null signature) are counted separately and do not
  contribute to the exit code.

Output (success):
  Records checked : 12 847
  Verified        : 12 800
  Unsigned        :     47  (pre-date integrity feature)
  Tampered        :      0

Output (failure):
  Audit ID | Model   | Model ID | Event   | Created At          | Reason
  ---------|---------|----------|---------|---------------------|----------------------------
  4291     | Invoice | 42       | updated | 2025-03-15 09:12:00 | Signature mismatch — record may have been tampered with
```

---

### `audit-events:archive`

```
Description:
  Move old audit records to cold storage (archive)

Usage:
  audit-events:archive [options]

Options:
  --days=N         Archive records older than N days. Default: config archive_after_days.
  --driver=NAME    'database' or 'json_file'. Default: config archive.driver.
  --dry-run        Show count of records that would be archived. No changes made.
  --chunk=N        Records per batch. Default: 500.
  --model=CLASS    Limit to a specific auditable_type (FQCN).

Exit codes:
  0   Always (errors surface as exceptions)

Examples:
  php artisan audit-events:archive
  php artisan audit-events:archive --dry-run
  php artisan audit-events:archive --days=365 --driver=json_file
  php artisan audit-events:archive --model="App\Models\Invoice" --chunk=1000
```

---

## 14. Configuration — Full Reference

```php
// config/audit-events.php

return [

    // ── Database ──────────────────────────────────────────────────────────────
    // Name of the live audit table
    'table_name'  => 'audit_events',

    // Eloquent model class. Override to extend ModelAudit.
    'model_class' => \SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class,


    // ── Column Mapping ────────────────────────────────────────────────────────
    // All column names are customizable. Change before running migrations.
    // 'morph_type' controls the auditable_id column type:
    //   'string'  varchar(64)  — recommended, supports int/UUID/ULID
    //   'integer' bigint unsigned
    //   'uuid'    char(36)
    //   'ulid'    char(26)
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
    // Disable to skip auditing on create/update globally
    'audit_on_create'  => true,
    'audit_on_update'  => true,

    // true  → delete all audit rows when a model is hard-deleted
    // false → keep all audit rows and add a final "deleted" entry
    'remove_on_delete' => true,

    // Whitelist for AUTOMATIC Eloquent events only.
    // saveHistory() and ModelAudit::record() always bypass this list.
    'events' => ['created', 'updated', 'deleted', 'restored'],


    // ── Security & Privacy ────────────────────────────────────────────────────
    // Always stripped from old_values and new_values before storage.
    // Merged with per-model overrides from getHiddenForAudit().
    'global_hidden' => [
        'password',
        'password_confirmation',
        'remember_token',
        'secret',
        'credit_card_number',
    ],


    // ── Deep JSON Diff ────────────────────────────────────────────────────────
    // getDiff() recursively diffs array/JSON fields.
    // max_depth: how many nesting levels to recurse into (0 = no recursion).
    'json_diff' => [
        'enabled'   => true,
        'max_depth' => 3,
    ],


    // ── User Resolver ─────────────────────────────────────────────────────────
    // Guards are tried in order. First non-null user wins.
    // resolver: callable that returns the authenticated user instance.
    //   null → use Auth::guard($guard)->user()
    'user' => [
        'guards'   => ['web', 'api', 'sanctum'],
        'resolver' => null,
    ],


    // ── Pruning (permanent deletion) ──────────────────────────────────────────
    // keep_for_days is read dynamically — safe to override per-tenant.
    // When enabled, the service provider auto-schedules model:prune daily.
    'pruning' => [
        'enabled'       => false,
        'keep_for_days' => 365,
    ],


    // ── Cryptographic Integrity ───────────────────────────────────────────────
    // Requires migration: add_signature_to_audit_events_table
    // key: null falls back to APP_KEY (base64 prefix auto-decoded).
    //      Set env('AUDIT_SIGNING_KEY') for key isolation.
    // algorithm: any PHP hash_hmac() algorithm — sha256, sha512, sha3-256, etc.
    'integrity' => [
        'enabled'   => false,
        'key'       => null,
        'algorithm' => 'sha256',
    ],


    // ── Archiving (cold storage) ──────────────────────────────────────────────
    // Requires migration: create_audit_events_archive_table (database driver)
    // archive_after_days: records older than this threshold are candidates.
    // driver: 'database' (transactional) or 'json_file' (JSONL files).
    // table_name: archive table name for the database driver.
    // path: filesystem directory for json_file driver.
    //       null → storage_path('audit-archives')
    'archive' => [
        'enabled'            => false,
        'archive_after_days' => 90,
        'driver'             => 'database',
        'table_name'         => 'audit_events_archive',
        'path'               => null,
    ],

];
```

---

## 15. User Resolution

The `user_id` stored in each audit is resolved in this priority order:

1. **`$causerId` parameter** (only for `ModelAudit::record()`)
2. **`AuditContext::getCauserId()`** — static injection for jobs
3. **`Auth::id()`** — current session user

The `user()` relation on `ModelAudit` resolves the User model class dynamically:

1. If `user.resolver` is callable, calls it and returns `get_class($result)`
2. Iterates `user.guards`, checks `auth.guards.{guard}.provider`, reads `auth.providers.{provider}.model`
3. Falls back to `App\Models\User` if it exists
4. Falls back to `Auth::user()` class
5. Falls back to `Illuminate\Database\Eloquent\Model`

---

## 16. MCP Integration

The package ships an optional MCP (Model Context Protocol) server for AI-assisted audit analysis. Requires `laravel/mcp:^0.4`.

**Server**: `SoftArtisan\LaravelAuditEvents\Mcp\Servers\AuditEventsServer`

**Tool** — `AuditHistoryTool`: accepts a model FQCN and optional ID, returns audit history as structured JSON for AI context.

**Prompt** — `AuditAnalysisPrompt`: a system prompt instructing the AI to analyze audit trails for anomalies, unusual patterns, and compliance concerns.

Register the server in your MCP configuration (see `laravel/mcp` documentation).

---

## 17. Multi-Tenancy Patterns

### Per-tenant retention (Pruning)

```php
// In a tenant-aware boot sequence or middleware:
config(['audit-events.pruning.keep_for_days' => $tenant->audit_retention_days]);
```

### Per-tenant hidden fields

Override `getHiddenForAudit()` to read from tenant configuration:

```php
class Invoice extends Model
{
    use IsAuditable;

    public function getHiddenForAudit(): array
    {
        $tenantHidden = config('tenant.audit_hidden_fields', []);
        return array_merge(parent::getHiddenForAudit(), $tenantHidden);
    }
}
```

### Per-tenant audit table (separate databases)

If each tenant has its own database, configure the audit table name and model dynamically:

```php
// In tenant bootstrapping
config(['audit-events.table_name' => 'audit_events']); // same name, different DB connection
```

Use a custom `ModelAudit` subclass with `$connection` set to the tenant connection.

### Queue job context with tenant ID

```php
AuditContext::actingAs($this->userId, [
    'tenant_id' => $this->tenantId,
    'source'    => 'background-job',
]);
```

The `tenant_id` is stored in the `context` column and can be queried:

```php
ModelAudit::where('context->tenant_id', $tenantId)->get();
```

---

## 18. Performance Considerations

### Write overhead

Each audit write is synchronous within the same transaction as the model save. For high-volume models:

- **Disable `audit_on_update`** for models with frequent low-value updates (e.g., `last_seen_at`, `view_count`)
- **Use `global_hidden`** to reduce payload size
- **Consider async**: wrap `persistAudit()` in a queued job by overriding the trait's `persistAudit` in a base model

### Query optimization

The `(auditable_type, auditable_id)` composite index covers the most common query patterns. For high-cardinality models, partition by `auditable_type`.

### Integrity overhead

`AuditSignatureService::getPreviousHash()` performs one additional `SELECT` per audit write (to fetch the latest signature for the chain scope). On MySQL/PostgreSQL with proper indexing, this is a single indexed row lookup.

For very high-frequency models (> 1000 audits/second on the same model instance), the hash chain scope becomes a write hotspot. Consider disabling the chain (`previous_hash = null`) for those models by overriding `persistAudit()`.

### Archive chunk size

Default: 500 records per batch. For large tables and slow disks, reduce to 100–200. For high-memory servers with fast storage, increase to 2000.

```bash
php artisan audit-events:archive --chunk=200
```

### Pruning

`model:prune` uses chunked soft-delete-aware queries. Run it off-peak (2–4 AM). For very large tables, use a dedicated MySQL partition pruning strategy instead.

---

## 19. Security Considerations

### Signing key

- **Use a dedicated key** (`AUDIT_SIGNING_KEY`), separate from `APP_KEY`. This ensures that rotating the application encryption key does not invalidate historical signatures.
- **Key length**: minimum 32 bytes (256 bits). Use `php artisan key:generate --show` style generation.
- **Storage**: store the signing key in a secrets manager (AWS Secrets Manager, HashiCorp Vault, Kubernetes Secret). Do not rely solely on `.env` for production compliance systems.
- **Key rotation**: when rotating, all unsigned-with-old-key records will fail verification until re-signed. Plan a re-signing migration for compliance-critical systems.

### What integrity does NOT protect against

- An attacker who has the signing key can forge valid signatures
- An attacker with DB write access AND the signing key can silently tamper
- The package does not provide non-repudiation (the signing key is server-side, not user-specific)

### Data masking

Always include sensitive fields in `global_hidden`. Common omissions:

```php
'global_hidden' => [
    'password', 'password_confirmation', 'remember_token',
    'secret', 'credit_card_number',
    'api_key', 'api_secret', 'access_token', 'refresh_token',  // Add these
    'ssn', 'date_of_birth', 'passport_number',                  // PII
    'bank_account', 'iban', 'routing_number',                   // Financial
],
```

### SQL injection

All queries use Eloquent's parameter binding. No raw SQL is constructed from user input.

### GDPR / Right to erasure

When a user requests data deletion:
1. Mask/anonymize the `user_id` column for their audit records
2. Consider replacing `old_values`/`new_values` containing their PII with `{"redacted": true}`
3. Do NOT delete audit records if they are required for compliance — document the retention policy

---

## 20. Testing Guide

### Setup in tests

```php
// tests/TestCase.php
protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(realpath(__DIR__.'/../database/migrations'));
    // Your fixture tables...
}

protected function defineEnvironment($app): void
{
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
}
```

### Testing with integrity disabled (default)

Integrity is `false` by default. No extra setup needed. Existing tests continue to work.

### Testing with integrity enabled

```php
beforeEach(function () {
    config()->set('audit-events.integrity.enabled', true);
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
});

it('signs audit records', function () {
    $invoice = Invoice::create(['amount' => 100]);
    $audit = $invoice->audits()->first();

    expect($audit->isSigned())->toBeTrue()
        ->and($audit->verifySignature())->toBeTrue();
});
```

### Asserting audit records in feature tests

```php
it('records an updated audit when invoice amount changes', function () {
    $invoice = Invoice::create(['amount' => 100]);
    $invoice->update(['amount' => 250]);

    $fields = config('audit-events.table_fields');

    expect($invoice->audits()->count())->toBe(2);

    $audit = $invoice->audits()->latest($fields['id'])->first();

    expect($audit->{$fields['event']})->toBe('updated')
        ->and($audit->{$fields['old_values']}['amount'])->toBe(100)
        ->and($audit->{$fields['new_values']}['amount'])->toBe(250);
});
```

### Asserting free events

```php
it('records a login event', function () {
    $user = User::factory()->create();
    ModelAudit::record('user.logged_in', ['ip' => '127.0.0.1'], $user->id);

    $fields = config('audit-events.table_fields');

    $audit = ModelAudit::where($fields['event'], 'user.logged_in')->first();

    expect($audit->{$fields['user_id']})->toBe($user->id)
        ->and($audit->{$fields['context']}['ip'])->toBe('127.0.0.1');
});
```

### Using the factory

```php
// Create audit records for testing stats, verification, etc.
ModelAudit::factory()->count(50)->create();
ModelAudit::factory()->updated()->forModel($invoice)->create();
ModelAudit::factory()->free()->count(10)->create();
```

---

## 21. Migration Guide — v1.x to v2.x

### Step-by-step upgrade

**1. Update package name:**

```bash
composer require softartisan/laravel-audit-events:^2.0
```

**2. Publish new config:**

```bash
php artisan vendor:publish --tag="laravel-audit-events-config" --force
```

**3. Update namespace references** across your codebase:

```bash
# Find all references
grep -r "LaravelModelAudits" app/ --include="*.php"
grep -r "model-audits" config/ --include="*.php"
```

| Before | After |
|---|---|
| `SoftArtisan\LaravelModelAudits\Concerns\IsAuditable` | `SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable` |
| `SoftArtisan\LaravelModelAudits\Models\ModelAudit` | `SoftArtisan\LaravelAuditEvents\Models\ModelAudit` |
| `SoftArtisan\LaravelModelAudits\AuditContext` | `SoftArtisan\LaravelAuditEvents\AuditContext` |
| `config('model-audits.xxx')` | `config('audit-events.xxx')` |

**4. Run migrations:**

```bash
php artisan migrate
```

This runs:
- `rename_model_audits_to_audit_events_table` — renames `model_audits → audit_events`
- `add_context_to_audit_events_table` — adds the `context` column
- `add_signature_to_audit_events_table` — adds `signature` and `previous_hash` columns
- `create_audit_events_archive_table` — creates the cold archive table

**5. Update scheduled commands** (if overriding the default schedule):

```php
// Before
$schedule->command('model-audits:stats');

// After
$schedule->command('audit-events:stats');
```

**6. Enable new features** (optional):

```php
// config/audit-events.php
'integrity' => ['enabled' => true, ...],
'archive'   => ['enabled' => true, ...],
```

### Keeping the old table name

If you prefer not to rename the table, override after publishing:

```php
// config/audit-events.php
'table_name' => 'model_audits',
```

The rename migration will be a no-op (it checks for table existence before acting).

### Zero-downtime considerations

The rename migration uses `Schema::hasTable()` guards and is reversible. For zero-downtime deployments:

1. Deploy the new package version with `table_name => 'model_audits'` temporarily
2. Verify application works
3. Run the rename migration during a maintenance window or with blue/green deployment
4. Update `table_name => 'audit_events'`
