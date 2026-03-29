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
];
