<?php

// Configuration for SoftArtisan/LaravelModelAudits
return [

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | The database table and Eloquent model that store your audit entries.
    | You usually don't need to change the model class unless you extend it.
    |
    */
    'table_name' => 'model_audits',

    'model_class' => \SoftArtisan\LaravelModelAudits\Models\ModelAudit::class,

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
        'id'           => 'audit_id',      // Primary key of the audit row
        'user_id'      => 'user_id',       // Foreign key to the user who performed the change
        'event'        => 'event',         // Event name: created, updated, deleted, restored
        'morph_prefix' => 'auditable',     // Generates auditable_id and auditable_type
        'morph_type'   => 'string',        // One of: string, integer, uuid, ulid
        'url'          => 'url',           // Request URL
        'ip_address'   => 'ip_address',    // Request IP
        'user_agent'   => 'user_agent',    // Request UA
        'old_values'   => 'old_values',    // JSON column storing previous attributes
        'new_values'   => 'new_values',    // JSON column storing new attributes
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
    | Events to Audit
    |--------------------------------------------------------------------------
    |
    | Whitelisted events that can be recorded. If an event is not present in
    | this list, attempts to record it will be ignored (a warning may be logged
    | in APP_DEBUG mode). You can add/remove events based on your needs.
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
    | User Resolver
    |--------------------------------------------------------------------------
    |
    | How to resolve the acting user ("causer"). By default, the package will
    | attempt guards in the order listed below. You can also provide a callable
    | resolver that returns the authenticated user instance.
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
    | Automatically remove audit rows older than the configured retention. To
    | enable pruning, set 'enabled' to true and schedule Laravel's model:prune
    | command in your app's Console\Kernel.
    |
    */
    'pruning' => [
        'enabled' => false,
        'keep_for_days' => 90,
    ],
];
