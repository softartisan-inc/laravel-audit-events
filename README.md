# Laravel Audit Events

[![Latest Version on Packagist](https://img.shields.io/packagist/v/softartisan/laravel-audit-events.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-audit-events)
[![Tests](https://img.shields.io/github/actions/workflow/status/softartisan-inc/laravel-audit-events/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/softartisan-inc/laravel-audit-events/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/softartisan/laravel-audit-events.svg?style=flat-square)](https://packagist.org/packages/softartisan/laravel-audit-events)

A lightweight, production-ready Laravel package that **automatically audits Eloquent model changes** while also providing **event-sourcing-style free events** for actions that have no Eloquent anchor — login, logout, CSV exports, PDF generation, permission syncs, and more.

> **v2.0 — breaking changes**: Package renamed from `softartisan/laravel-model-audits` to `softartisan/laravel-audit-events`. See the [Upgrade Guide](#upgrade-from-v1x) below.

---

## Features

- Automatic audit trail for `created`, `updated`, `deleted`, `restored` Eloquent events
- `AuditContext::actingAs()` — inject the causer in queue jobs where `Auth::id()` is `null`
- `ModelAudit::record()` — record free-standing events (login, export, PDF generation…) without an Eloquent anchor
- `TracksRelationChanges` — manually track pivot/sync relation changes
- Deep JSON diff — recursively diff array/JSON fields to pinpoint exact sub-key changes
- Configurable events whitelist (automatic events only; free events bypass it)
- Global + per-model attribute masking (password, tokens, credit cards…)
- Configurable pruning with per-tenant retention periods
- `audit-events:stats` artisan command
- MCP server integration (optional, requires `laravel/mcp`)
- PHP 8.4 · Laravel 12 · Pest test suite · PHPStan level 5

---

## Installation

```bash
composer require softartisan/laravel-audit-events
```

Publish the config and run the migration:

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

The bundled `rename_model_audits_to_audit_events_table` migration renames `model_audits → audit_events` safely (it checks for table existence before acting). If you prefer to keep the old table name, override the config:

```php
// config/audit-events.php
'table_name' => 'model_audits',
```

---

## Basic Usage

### 1. Attach `IsAuditable` to a model

```php
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;

class Invoice extends Model
{
    use IsAuditable;
}
```

Every `created`, `updated`, `deleted`, and `restored` event now produces an audit record automatically.

### 2. Query the audit history

```php
$invoice->audits()->get();           // all audits
$invoice->getCreatedHistory()->get();
$invoice->getUpdatedHistory()->get();
$invoice->getDeletedHistory()->get();
$invoice->getRestoredHistory()->get();

// Diff between old and new values
$audit = $invoice->audits()->latest()->first();
$diff  = $audit->getDiff();
// ['amount' => ['old' => 100, 'new' => 250]]
```

### 3. Restore a model to a previous state

```php
$audit = $invoice->audits()->latest()->first();
$audit->restore(); // forceFills old_values back onto the model
```

### 4. Mask sensitive attributes

Globally in `config/audit-events.php`:

```php
'global_hidden' => ['password', 'remember_token', 'secret'],
```

Per model:

```php
class User extends Model
{
    use IsAuditable;

    public function getHiddenForAudit(): array
    {
        return array_merge(parent::getHiddenForAudit(), ['ssn', 'date_of_birth']);
    }
}
```

---

## AuditContext — Causer injection for jobs

In queue jobs, `Auth::id()` returns `null` because there is no active session. Use `AuditContext::actingAs()` to inject the user manually.

```php
use SoftArtisan\LaravelAuditEvents\AuditContext;

class ImportProductsJob implements ShouldQueue
{
    public function handle(): void
    {
        AuditContext::actingAs($this->userId, [
            'source'   => 'import-job',
            'batch_id' => $this->batchId,
        ]);

        // All audits created inside this job will use $this->userId
        Product::create([...]);

        AuditContext::reset(); // Always reset at the end
    }
}
```

---

## ModelAudit::record() — Free events

Record semantic events that have no Eloquent model anchor:

```php
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

// In an Auth listener
ModelAudit::record('user.logged_in', ['ip' => request()->ip()], $user->id);

// In an export job
ModelAudit::record('csv.exported', [
    'tenant_id' => tenant('id'),
    'resource'  => 'fixed-assets',
    'count'     => 1500,
], $this->userId);

// PDF generation
ModelAudit::record('pdf.generated', ['invoice_id' => $invoice->id], auth()->id());
```

The signature:

```php
ModelAudit::record(
    string $event,
    array  $context   = [],
    int|string|null $causerId = null,
): ModelAudit
```

Free events are **never** filtered by the events whitelist.

---

## saveHistory() — Manual event on a model

```php
// Record a custom event bound to a specific model
$invoice->saveHistory('invoice.sent', [], [], ['recipient' => 'client@example.com']);
```

```php
public function saveHistory(
    string $event,
    array  $oldValues = [],
    array  $newValues = [],
    array  $context   = [],
): void
```

Free events — not filtered by the whitelist.

---

## TracksRelationChanges — Pivot / sync tracking

Laravel does not emit Eloquent events when pivot tables change. Use this trait alongside `IsAuditable` to manually record relation syncs.

```php
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;
use SoftArtisan\LaravelAuditEvents\Concerns\TracksRelationChanges;

class Role extends Model
{
    use IsAuditable, TracksRelationChanges;
}
```

```php
// In RoleService::syncPermissions()
$before = $role->permissions->pluck('name')->all();
$role->syncPermissions($permissionIds);
$after  = $role->fresh()->permissions->pluck('name')->all();

$role->recordRelationAudit('permissions', $before, $after, ['actor' => auth()->id()]);
```

The audit event will be `relation.synced`.

---

## Deep JSON Diff

When a model has a JSON/array field, `getDiff()` recursively diffs it to show exactly which sub-keys changed:

```php
// Before: extra_fields = ['a' => 1, 'b' => 2]
// After:  extra_fields = ['a' => 1, 'b' => 99]

$diff = $audit->getDiff();

// Result:
// [
//   'extra_fields' => [
//     'old'  => ['a' => 1, 'b' => 2],
//     'new'  => ['a' => 1, 'b' => 99],
//     'diff' => ['b' => ['old' => 2, 'new' => 99]],
//   ],
// ]
```

Configure in `config/audit-events.php`:

```php
'json_diff' => [
    'enabled'   => true,
    'max_depth' => 3, // Maximum recursion depth
],
```

---

## audit-events:stats

```bash
php artisan audit-events:stats
```

Displays:
- Total number of audit events
- Breakdown by event type
- Top 5 most audited model classes
- Date range (oldest → newest audit)
- Approximate table size (MySQL/PostgreSQL)

---

## Context column

Every audit record has a `context` JSON column for arbitrary payload:

```php
$article->saveHistory('article.published', [], [], [
    'publisher_id' => auth()->id(),
    'channel'      => 'newsletter',
]);
```

---

## Pruning

Set `pruning.enabled` to `true` and schedule the command:

```php
// bootstrap/app.php or Console/Kernel.php
Schedule::command('model:prune', ['--model' => [\SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class]])->daily();
```

When `pruning.enabled` is `true`, the package auto-schedules this command for you.

### Multi-tenant retention

The `keep_for_days` value is read **dynamically** at each pruning run — never cached — so every tenant can have its own retention policy:

```php
// Tenant with 5-year legal obligation (config/audit-events.php in tenant context)
'pruning' => [
    'enabled'      => true,
    'keep_for_days' => 1825, // 5 years
],

// Standard tenant
'pruning' => [
    'enabled'      => true,
    'keep_for_days' => 365,
],
```

---

## Configuration reference

```php
return [
    'table_name'  => 'audit_events',
    'model_class' => \SoftArtisan\LaravelAuditEvents\Models\ModelAudit::class,

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

    'audit_on_create'  => true,
    'audit_on_update'  => true,
    'remove_on_delete' => true,

    // Whitelist for automatic Eloquent events only
    'events' => ['created', 'updated', 'deleted', 'restored'],

    'global_hidden' => ['password', 'password_confirmation', 'remember_token', 'secret', 'credit_card_number'],

    'json_diff' => ['enabled' => true, 'max_depth' => 3],

    'user' => ['guards' => ['web', 'api', 'sanctum'], 'resolver' => null],

    'pruning' => ['enabled' => false, 'keep_for_days' => 365],
];
```

---

## Testing

```bash
composer test
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
