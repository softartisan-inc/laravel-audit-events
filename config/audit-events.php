<?php

use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

// Configuration for SoftArtisan/LaravelAuditEvents
return [

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | The database table and Eloquent model that store your audit entries.
    | Renamed from 'model_audits' to 'audit_events' in v2.0 (breaking change).
    | Existing installations must run the rename migration.
    |
    */
    'table_name' => 'audit_events',

    'model_class' => ModelAudit::class,

    /*
    |--------------------------------------------------------------------------
    | Audit Columns Mapping
    |--------------------------------------------------------------------------
    |
    | Customize the column names used by the audits table. You can also choose
    | the morph key strategy used by the polymorphic relation.
    |
    | morph_type options:
    |   - 'string'  (recommended) supports integer IDs, UUIDs, and ULIDs.
    |   - 'integer' optimized for auto-increment integer IDs.
    |   - 'uuid'    optimized for UUIDs only.
    |   - 'ulid'    optimized for ULIDs only.
    |
    */
    'table_fields' => [
        'id' => 'audit_id',      // Primary key of the audit row
        'user_id' => 'user_id',       // Foreign key to the user who performed the change
        'event' => 'event',         // Event name: created, updated, deleted, restored
        'morph_prefix' => 'auditable',     // Generates auditable_id and auditable_type
        'morph_type' => 'string',        // One of: string, integer, uuid, ulid
        'url' => 'url',           // Request URL
        'ip_address' => 'ip_address',    // Request IP
        'user_agent' => 'user_agent',    // Request UA
        'old_values' => 'old_values',    // JSON column storing previous attributes
        'new_values' => 'new_values',    // JSON column storing new attributes
        'context' => 'context',       // JSON column for arbitrary event payload (v2)
    ],

    // Toggle which model lifecycle events produce audits
    'audit_on_create' => true,
    'audit_on_update' => true,

    // When a model is hard-deleted:
    // - true  => remove all audits for the model
    // - false => keep audits and also record a final "deleted" entry
    'remove_on_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Events Whitelist (Eloquent auto-events only)
    |--------------------------------------------------------------------------
    |
    | Only applies to automatic Eloquent lifecycle events (created, updated,
    | deleted, restored). Events recorded via saveHistory() or ModelAudit::record()
    | are NOT subject to this whitelist — the caller is responsible.
    |
    */
    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
        // 'retrieved', // Usually too heavy — keep disabled by default
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & Privacy
    |--------------------------------------------------------------------------
    |
    | Attributes that must NEVER be logged. These are merged with any per-model
    | hidden list provided via the trait's $hidden_for_audit property or
    | overridden getHiddenForAudit() method.
    |
    */
    'global_hidden' => [
        'password',
        'password_confirmation',
        'remember_token',
        'secret',
        'credit_card_number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deep JSON Diff
    |--------------------------------------------------------------------------
    |
    | When enabled, getDiff() will recursively diff array/JSON fields to show
    | exactly which sub-keys changed, up to max_depth levels deep.
    |
    */
    'json_diff' => [
        'enabled' => true,
        'max_depth' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    |
    | How to resolve the acting user ("causer"). By default, the package will
    | attempt guards in the order listed below. You can also provide a callable
    | resolver that returns the authenticated user instance.
    | In jobs, use AuditContext::actingAs($userId) to inject the causer manually.
    |
    */
    'user' => [
        'guards' => ['web', 'api', 'sanctum'],
        'resolver' => null, // If set, must be callable. Null => use Auth::guard(...)->user()
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning (Auto-cleanup)
    |--------------------------------------------------------------------------
    |
    | Automatically remove audit rows older than the configured retention.
    | The keep_for_days value is read dynamically at every pruning run —
    | never cached — so multi-tenant apps can override it per-tenant.
    |
    | Example (bootstrap/app.php or Console/Kernel.php):
    |   Schedule::command('model:prune', ['--model' => [ModelAudit::class]])->daily();
    |
    | Multi-tenant example — tenant with 5-year legal obligation:
    |   'pruning' => ['enabled' => true, 'keep_for_days' => 1825],
    |
    */
    'pruning' => [
        'enabled' => false,
        'keep_for_days' => 365,  // Override per-tenant for different retention policies
    ],

    /*
    |--------------------------------------------------------------------------
    | Cryptographic Integrity
    |--------------------------------------------------------------------------
    |
    | When enabled, every new audit record receives an HMAC signature covering
    | its payload fields. A hash chain is maintained per (auditable_type,
    | auditable_id) tuple: each new record's signed payload includes the
    | previous record's signature as `previous_hash`, making insertion,
    | deletion, or reordering of rows detectable within a model's history.
    |
    | key:       Signing key. Null falls back to APP_KEY (base64-decoded automatically).
    |            Set AUDIT_SIGNING_KEY in .env for a dedicated key.
    | algorithm: Any algorithm supported by PHP hash_hmac() — sha256, sha512, etc.
    |
    | Verify integrity: php artisan audit-events:verify
    |
    | Note: Enabling this after records already exist will leave pre-existing
    | rows with null signatures. The verify command reports them as "unsigned"
    | rather than "tampered".
    |
    | Requires migration: add_signature_to_audit_events_table
    |
    */
    'integrity' => [
        'enabled' => false,
        'key' => null,  // Set to env('AUDIT_SIGNING_KEY') in your config publish. Null falls back to APP_KEY.
        'algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Archiving (Cold Storage)
    |--------------------------------------------------------------------------
    |
    | Move audit records older than `archive_after_days` to cold storage instead
    | of deleting them. This satisfies legal retention requirements while keeping
    | the hot audit_events table lean.
    |
    | Drivers:
    |   database  — Copies rows to a dedicated archive table (same DB connection).
    |               Each batch is wrapped in a transaction: archive then delete.
    |   json_file — Appends JSONL lines to daily files under `path`.
    |               Files can be compressed and shipped to S3 or a log aggregator.
    |
    | archive_after_days: Records older than this are candidates for archiving.
    | table_name:         Archive table (database driver).
    | path:               Filesystem directory for JSONL files (json_file driver).
    |
    | Run:      php artisan audit-events:archive
    | Schedule: $schedule->command('audit-events:archive')->weekly();
    |
    | Requires migration: create_audit_events_archive_table (database driver)
    |
    */
    'archive' => [
        'enabled' => false,
        'archive_after_days' => 90,
        'driver' => 'database',    // 'database' | 'json_file'
        'table_name' => 'audit_events_archive',
        'path' => null,            // Defaults to storage_path('audit-archives') when null
    ],
];
