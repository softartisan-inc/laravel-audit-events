# Laravel Audit Events — Use-Case Cookbook

A complete, scenario-driven guide to **every way you can use this package**. Each
section answers a concrete "I need to…" with the exact code. For deep API/config
reference see [`README.md`](./README.md); for internals see
[`docs/technical.md`](./docs/technical.md).

> **Mental model.** Everything ends up as one row in the `audit_events` table:
> *who* (`user_id`), *what* (`event`), *on what* (`auditable_type`/`auditable_id`,
> nullable), *before/after* (`old_values`/`new_values`), *arbitrary payload*
> (`context` JSON), *where/how* (`url`, `ip_address`, `user_agent`), *when*
> (`created_at`), and an optional tamper-evident `signature`/`previous_hash`.
> The table lives on the **current DB connection** — no coupling to any
> multi-tenancy package.

---

## Decision guide — which API do I use?

| You want to record… | Use | Anchored to a model? | Whitelist applies? |
|---|---|---|---|
| Automatic create/update/delete/restore of a model | `IsAuditable` trait | Yes | Yes (`events` config) |
| A manual, named action **about a model** ("X validated invoice #5") | `$model->saveHistory(...)` | Yes | No |
| A manual, named action **with no model** (login, export, PDF) | `ModelAudit::record(...)` | No | No |
| A relation/pivot sync (roles ↔ permissions) | `TracksRelationChanges` trait | Yes | No |
| Reverting a model to a past state | `$audit->restore()` | — | — |

The right causer in a queue/command context: wrap any of the above with
`AuditContext::actingAs($userId)` (see UC-4).

---

## UC-1 — Audit every change to a model (automatic)

```php
use SoftArtisan\LaravelAuditEvents\Concerns\IsAuditable;

class Invoice extends Model
{
    use IsAuditable;
}
```

That's it. `created`, `updated`, `deleted`, `restored` are now recorded
automatically with old/new values, causer, URL, IP and user-agent. Apply the
trait to **every business model** you want a trail for.

> Only events listed in `config('audit-events.events')` are auto-recorded
> (`created/updated/deleted/restored` by default). `retrieved` is intentionally
> off (too noisy).

---

## UC-2 — Record a manual, named action about a model (from a controller)

"The current user sent invoice #5", "the manager approved mission #12".

```php
public function send(Invoice $invoice)
{
    // ...domain logic...

    $invoice->saveHistory(
        'invoice.sent',                                   // semantic event name
        [],                                               // old values (optional)
        ['channel' => 'email'],                           // new values (optional)
        ['recipient' => $invoice->client_email],          // context payload
    );
}
```

- Anchored to `$invoice` (so it shows in `$invoice->audits()` and on the asset
  timeline).
- The causer is resolved automatically from the authenticated user.
- **Not** subject to the events whitelist — any event name works.

---

## UC-3 — Record a free-standing event (no Eloquent model)

Login, logout, CSV export, PDF generation, a webhook received, a sync run…

```php
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

ModelAudit::record('user.logged_in', ['ip' => request()->ip()], $user->id);
ModelAudit::record('csv.exported', ['resource' => 'fixed-assets', 'count' => 1500]);
ModelAudit::record('pdf.generated', ['document' => 'inventory-2026']);
```

Signature: `ModelAudit::record(string $event, array $context = [], int|string|null $causerId = null)`.
`auditable_type`/`auditable_id` are `null`. URL/IP/user-agent are captured when an
HTTP request is active. Causer falls back to `AuditContext` then `Auth::id()`.

**Wire it to framework events** (recommended for auth):

```php
// EventServiceProvider / AppServiceProvider::boot()
Event::listen(Login::class,  fn ($e) => ModelAudit::record('user.logged_in',  [], $e->user->id));
Event::listen(Logout::class, fn ($e) => ModelAudit::record('user.logged_out', [], $e->user?->id));
Event::listen(Failed::class, fn ($e) => ModelAudit::record('user.login_failed', ['email' => $e->credentials['email'] ?? null]));
```

---

## UC-4 — Correct causer inside queue jobs & console commands

In a queued job `Auth::id()` is `null`, so audits would have no causer. Inject it:

```php
use SoftArtisan\LaravelAuditEvents\AuditContext;

public function handle(): void
{
    AuditContext::actingAs($this->userId, ['source' => 'import-job', 'batch_id' => $this->batchId]);

    try {
        // every saveHistory()/record()/auto-audit in here now attributes to $this->userId
        $asset->saveHistory('asset.status_changed', ['status' => $old], ['status' => $new], [
            'mission_id' => $this->missionId,
        ]);
    } finally {
        AuditContext::reset(); // always reset so the next job on this worker is clean
    }
}
```

> The `extra` passed to `actingAs()` is available via `AuditContext::getExtra()`
> if you want to merge it into context yourself.

---

## UC-5 — Track relation / pivot syncs (roles ↔ permissions)

Laravel emits no model event when you `sync()` a pivot, so this is explicit:

```php
use SoftArtisan\LaravelAuditEvents\Concerns\TracksRelationChanges;

class Role extends Model
{
    use IsAuditable, TracksRelationChanges;
}

// In your service, around the sync:
$before = $role->permissions->pluck('name')->all();
$role->syncPermissions($permissionIds);
$after = $role->fresh()->permissions->pluck('name')->all();

$role->recordRelationAudit('permissions', $before, $after, ['changed_by_ui' => true]);
// event = 'relation.synced', old_values = ['permissions' => $before], new_values = ['permissions' => $after]
```

---

## UC-6 — Revert a model to a previous state

```php
$audit = $invoice->audits()->latest()->first();
$audit->restore(); // forceFills old_values back onto the model and saves
```

Columns that no longer exist on the table are skipped. Pair it with UC-2 to log
the revert itself:

```php
$invoice->saveHistory('invoice.reverted', [], [], ['from_audit_id' => $audit->getKey()]);
```

---

## UC-7 — Read & display the history

Per-model:

```php
$invoice->audits()->latest()->get();
$invoice->getUpdatedHistory()->get();
$invoice->getAuditHistory('invoice.sent')->get();
$audit->getDiff(); // ['amount' => ['old' => 100, 'new' => 250]]  (+ deep JSON diff)
```

Across the whole table (scopes):

```php
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

ModelAudit::whereEvent('asset.status_changed')->get();
ModelAudit::whereContext('mission_id', 42)->get();        // JSON path, portable
ModelAudit::forAuditable($asset)->get();                  // indexed morph columns

// Aggregate by a value inside new_values / context (in PHP for bounded sets):
$byStatus = ModelAudit::whereEvent('asset.status_changed')
    ->whereContext('mission_id', $missionId)
    ->get()
    ->groupBy(fn ($a) => data_get($a->getAttribute('new_values'), 'status'))
    ->map->count();
```

Build a read API (controller) that filters by event/subject/user/date/search and
returns a resource — see UC-8 for the export counterpart.

---

## UC-8 — Export the history for an auditor (CSV)

Stream a filtered export so an auditor gets "everything about X: who, when, on
what, from where, how".

```php
public function exportCsv(Request $request): StreamedResponse
{
    $fields = config('audit-events.table_fields');
    $morph  = $fields['morph_prefix'];

    return response()->streamDownload(function () use ($request, $fields, $morph) {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, ['date','event','actor_id','actor_email','subject_type','subject_id','url','ip','user_agent','old','new','context']);

        ModelAudit::query()
            ->when($request->event, fn ($q, $e) => $q->whereEvent($e))
            ->when($request->user_id, fn ($q, $u) => $q->where($fields['user_id'], $u))
            ->with('user')->latest('created_at')
            ->chunk(500, function ($rows) use ($out, $fields, $morph) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        optional($r->created_at)->toIso8601String(),
                        $r->getAttribute($fields['event']),
                        $r->getAttribute($fields['user_id']),
                        $r->user->email ?? null,
                        $r->getAttribute("{$morph}_type"),
                        $r->getAttribute("{$morph}_id"),
                        $r->getAttribute($fields['url']),
                        $r->getAttribute($fields['ip_address']),
                        $r->getAttribute($fields['user_agent']),
                        json_encode($r->getAttribute($fields['old_values'])),
                        json_encode($r->getAttribute($fields['new_values'])),
                        json_encode($r->getAttribute($fields['context'])),
                    ]);
                }
            });

        fclose($out);
    }, 'audit-history-'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv']);
}
```

---

## UC-9 — A cross-context action recorded in TWO histories (multi-tenant)

Some actions span two databases — e.g. a **central admin impersonating a tenant**.
Record one entry per context, each on its own connection (never a "cross" row):

```php
// Central side (runs on the central connection):
ModelAudit::record('impersonation.started', [
    'tenant_id'         => $tenant->getKey(),
    'impersonated_email'=> $targetEmail,
], $centralUser->id);

// Tenant side (runs inside the tenant DB context):
$tenant->run(function () use ($targetEmail, $centralUser) {
    $tenantUser = \App\Models\User::where('email', $targetEmail)->first();
    $tenantUser?->saveHistory('impersonation.started', [], [], [
        'central_user_email' => $centralUser->email,
    ]);
});
```

Resolve the audit model via `config('audit-events.model_class')` if you want to
avoid a compile-time dependency on the concrete class. Make these writes
best-effort (`try/catch`) so audit never blocks the action.

---

## UC-10 — Domain status changes via an event subscriber

When status transitions are already modelled as Laravel events
(`MissionStarted`, `MissionCancelled`…), subscribe a listener that audits them —
this keeps audit logic out of the controllers.

```php
class LogMissionStatusChange
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MissionPlanned::class,  [self::class, 'handlePlanned']);
        $events->listen(MissionStarted::class,  [self::class, 'handleStarted']);
        $events->listen(MissionCancelled::class,[self::class, 'handleCancelled']);
    }

    public function handleStarted($e): void
    {
        $e->mission->saveHistory('mission.started', [], [], [
            'mission_code' => $e->mission->mission_code,
        ]);
    }
    // ...
}

// AppServiceProvider::boot()
Event::subscribe(LogMissionStatusChange::class);
```

> **Gotcha:** a listener with `handleX()` methods is **not** auto-discovered —
> you must register it (`Event::subscribe`) or it silently never fires.

---

## UC-11 — Replace a legacy custom history table with this module

You have an old `activity_logs` / `status_history` table. Migrate to the module:

1. Reroute every writer to `saveHistory()` / `ModelAudit::record()` (anchor to the
   relevant model; put the typed columns into `context`).
2. Back-fill old rows in a data migration, then drop the table:

```php
DB::table('activity_logs')->orderBy('id')->chunk(500, function ($rows) {
    $batch = $rows->map(fn ($r) => [
        'auditable_type' => $r->subject_type,
        'auditable_id'   => $r->subject_id,
        'event'          => strtolower(str_replace('_', '.', $r->action)),
        'user_id'        => $r->user_id,
        'old_values'     => json_encode([]),
        'new_values'     => json_encode([]),
        'context'        => json_encode(array_merge(['description' => $r->description], json_decode($r->metadata ?? '[]', true) ?: [])),
        'created_at'     => $r->created_at,
        'updated_at'     => $r->updated_at,
    ])->all();
    DB::table(config('audit-events.table_name'))->insert($batch);
});
Schema::dropIfExists('activity_logs');
```

Back-filled rows are unsigned — `audit-events:verify` reports them as *unsigned*
(not *tampered*), which is expected.

---

## UC-12 — Mask sensitive attributes

Never let secrets land in `old_values`/`new_values`:

```php
// Global (config/audit-events.php)
'global_hidden' => ['password', 'remember_token', 'secret', 'api_key', 'token'],

// Per model
class User extends Model
{
    use IsAuditable;
    protected array $hidden_for_audit = ['two_factor_secret'];
}
```

---

## UC-13 — Tamper-evident history (cryptographic integrity)

```php
// config/audit-events.php
'integrity' => [
    'enabled'   => true,
    'key'       => env('AUDIT_SIGNING_KEY'), // null => falls back to APP_KEY
    'algorithm' => 'sha256',
],
```

Each new row is HMAC-signed and chained (`previous_hash`) per
`(auditable_type, auditable_id)`. Verify integrity:

```bash
php artisan audit-events:verify                 # all
php artisan audit-events:verify --model="App\Models\Invoice" --id=5
php artisan audit-events:verify --from=2026-01-01 --until=2026-06-30 --fail-fast
```

Exit code `0` = intact, `1` = failures. Wire it into a scheduled integrity check.

> **For an auditor-facing trail, keep `remove_on_delete => false`** so deleting a
> record preserves its history and records a final `deleted` event. With `true`,
> a hard delete wipes the model's audit rows — usually NOT what you want.

---

## UC-14 — Retention: pruning vs cold archiving

```php
// Hard delete old rows (irreversible):
'pruning' => ['enabled' => true, 'keep_for_days' => 365],
// Schedule: Schedule::command('model:prune', ['--model' => [ModelAudit::class]])->daily();

// OR move old rows to cold storage (keeps them for legal retention):
'archive' => ['enabled' => true, 'archive_after_days' => 90, 'driver' => 'database'],
// php artisan audit-events:archive   (Schedule weekly)
```

Use **archiving** when you must retain history (compliance); **pruning** only when
old history is genuinely disposable. `keep_for_days` is read dynamically, so each
tenant can override it (UC-15).

---

## UC-15 — Multi-tenant isolation (tenant DB vs central DB)

The package writes to the **current connection**, so under `stancl/tenancy`:

- During a tenant request/job → lands in that tenant's DB.
- Outside tenant context → lands in the central DB.

Requirements:

1. The `audit_events` table must exist in **every** tenant DB **and** the central
   DB — place the package migrations in both your central and tenant migration
   paths (`php artisan migrate` + `php artisan tenants:migrate`).
2. Per-tenant retention: publish `config/audit-events.php` and override
   `pruning.keep_for_days` per tenant (e.g. 1825 for a 5-year legal obligation).
3. Cross-context writes (UC-9) must run inside the correct `tenant->run()` to hit
   the intended DB.

Verify isolation in a test: an audit created in tenant A's context must not be
visible in the central DB nor in tenant B.

---

## UC-16 — Use in a plain (non-tenant) Laravel app

The package has **zero** dependency on any tenancy package. In a standard app it
just uses the default connection. Optionally pin a specific connection:

```php
// config/audit-events.php (optional)
'connection' => env('AUDIT_CONNECTION'), // null = default connection
```

Nothing else changes — the same trait/`record()`/scopes/commands work.

---

## UC-17 — Operational stats & monitoring

```bash
php artisan audit-events:stats
# total rows, breakdown by event, top audited models, table size, oldest/newest
```

Pair with `audit-events:verify` in a daily scheduled job and alert on a non-zero
exit code.

---

## UC-18 — Consuming the history in a frontend

Your UI fetches `/audit-logs` (or whatever route wraps the resource). The
response exposes `event`, `subject`, `actor`, `changes`, `old_values`,
`new_values`, `context` (request metadata) and `payload` (the `context` column).

**Render every event type, not just CRUD.** Since the module emits semantic
events (`impersonation.started`, `mission.*`, `asset.status_changed`, …), the UI
must label/style them and **fall back gracefully** for unknown ones instead of
mislabelling them:

```ts
function getEventVisual(event: string) {
  return KNOWN[event] ?? {
    // derive a readable label from the event string; never hard-code "Updated"
    label: event.replace(/[._]/g, ' ').replace(/^\w/, c => c.toUpperCase()),
    ...neutralStyle,
  }
}
```

Also surface `payload.description` / `payload.reason` for semantic events, and add
the semantic events to any "filter by event" dropdown.

---

## Cheat-sheet

```php
// Auto CRUD
use IsAuditable;

// Manual, anchored
$model->saveHistory('domain.action', $old, $new, $context);

// Free, no model
ModelAudit::record('user.logged_in', $context, $causerId);

// Job/command causer
AuditContext::actingAs($userId); /* ... */ AuditContext::reset();

// Pivot sync
$model->recordRelationAudit('permissions', $before, $after);

// Revert
$audit->restore();

// Query
ModelAudit::forAuditable($m)->whereEvent('x')->whereContext('k', $v)->latest()->get();

// Integrity / retention
php artisan audit-events:verify
php artisan audit-events:archive
php artisan audit-events:stats
```

## Common gotchas

- **Auto events are whitelisted; manual ones are not.** A custom auto-event won't
  fire unless added to `config('audit-events.events')`. `saveHistory()` /
  `record()` are never filtered.
- **`remove_on_delete => true` wipes history on hard delete.** Use `false` for an
  auditor-facing trail.
- **`context` is JSON and not indexed by default.** Frequent filters on a
  `context` key (e.g. `mission_id`) at scale want a generated column + index, or
  anchor the event to a model and query the indexed morph columns instead.
- **Listeners with `handleX()` methods aren't auto-discovered** — register them
  via `Event::subscribe`.
- **Restarting workers after deploy.** Code/autoload changes require
  `php artisan queue:restart` (or `horizon:terminate`) — long-running workers hold
  the old autoloader.
- **The table must exist on the target connection** before any audited write
  (run migrations on central *and* every tenant DB).
