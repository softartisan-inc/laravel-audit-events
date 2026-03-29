<?php

namespace SoftArtisan\LaravelAuditEvents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use SoftArtisan\LaravelAuditEvents\AuditContext;

/**
 * @phpstan-consistent-constructor
 */
class ModelAudit extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('audit-events.table_name', 'audit_events');
        $fields = config('audit-events.table_fields');
        $this->primaryKey = $fields['id'] ?? 'audit_id';
        $morphName = $fields['morph_prefix'] ?? 'auditable';

        $this->fillable = [
            $fields['event'],
            $fields['user_id'],
            $fields['url'],
            $fields['ip_address'],
            $fields['user_agent'],
            $fields['old_values'],
            $fields['new_values'],
            $fields['context'],
            "{$morphName}_type",
            "{$morphName}_id",
        ];
    }

    /**
     * Override getCasts() so the JSON column casts are available during setAttribute()
     * even when called from inside parent::__construct() (before our constructor body runs).
     * This fixes "Array to string conversion" when factories pass array values at construction time.
     */
    public function getCasts(): array
    {
        $fields = config('audit-events.table_fields');

        return array_merge(parent::getCasts(), [
            $fields['old_values'] => 'array',
            $fields['new_values'] => 'array',
            $fields['context'] => 'array',
        ]);
    }

    /**
     * Record a free-standing audit event not bound to any Eloquent model.
     *
     * Use this for actions that have no Eloquent anchor: login, logout,
     * CSV/PDF exports, permission syncs initiated outside the model, etc.
     * Free events are never filtered by the events whitelist.
     *
     * @param  string  $event  Semantic event name (e.g. "user.logged_in")
     * @param  array  $context  Arbitrary payload stored in the context column
     * @param  int|string|null  $causerId  Override the acting user (falls back to AuditContext then Auth)
     */
    public static function record(
        string $event,
        array $context = [],
        int|string|null $causerId = null,
    ): self {
        $fields = config('audit-events.table_fields');
        $userId = $causerId ?? AuditContext::getCauserId() ?? Auth::id();

        $data = [
            $fields['event'] => $event,
            $fields['user_id'] => $userId,
            $fields['old_values'] => [],
            $fields['new_values'] => [],
            $fields['context'] => $context,
        ];

        try {
            $data[$fields['url']] = Request::fullUrl();
            $data[$fields['ip_address']] = Request::ip();
            $data[$fields['user_agent']] = Request::userAgent();
        } catch (\Throwable) {
            // Not in an HTTP context (e.g. CLI / queue)
        }

        $instance = new self;
        $instance->forceFill($data)->save();

        return $instance;
    }

    /**
     * Define a polymorphic relationship.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relationship to the user ("causer").
     */
    public function user(): BelongsTo
    {
        $fields = config('audit-events.table_fields');
        $userModel = $this->resolveUserModelClass();

        return $this->belongsTo($userModel, $fields['user_id']);
    }

    /**
     * Restore the parent (auditable) model to the state described in old_values.
     * Columns that no longer exist in the table are silently skipped.
     */
    public function restore(): ?Model
    {
        $auditable = $this->auditable;
        if (! $auditable) {
            return null;
        }

        $fields = config('audit-events.table_fields');
        $oldValues = (array) ($this->getAttribute($fields['old_values']) ?? []);

        if (empty($oldValues)) {
            return $auditable;
        }

        $table = $auditable->getTable();
        $filtered = [];
        foreach ($oldValues as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $filtered[$column] = $value;
            }
        }

        if (! empty($filtered)) {
            $auditable->forceFill($filtered);
            $auditable->save();
        }

        return $auditable;
    }

    /**
     * Return a diff map between old_values and new_values.
     *
     * For fields whose values are arrays, a recursive sub-diff is included
     * under the 'diff' key when json_diff is enabled in config.
     *
     * Example output:
     *   [
     *     'name'        => ['old' => 'Alice', 'new' => 'Bob'],
     *     'extra_fields'=> ['old' => [...], 'new' => [...], 'diff' => ['b' => ['old'=>2,'new'=>99]]],
     *   ]
     */
    public function getDiff(): array
    {
        $fields = config('audit-events.table_fields');
        $old = (array) ($this->getAttribute($fields['old_values']) ?? []);
        $new = (array) ($this->getAttribute($fields['new_values']) ?? []);

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];

        foreach ($keys as $key) {
            $o = $old[$key] ?? null;
            $n = $new[$key] ?? null;

            if ($o === $n) {
                continue;
            }

            $entry = ['old' => $o, 'new' => $n];

            if (is_array($o) && is_array($n) && config('audit-events.json_diff.enabled', true)) {
                $subDiff = $this->deepDiff($o, $n);
                if (! empty($subDiff)) {
                    $entry['diff'] = $subDiff;
                }
            }

            $diff[$key] = $entry;
        }

        return $diff;
    }

    /**
     * Prunable scope — returns records older than the configured retention.
     *
     * The keep_for_days value is read dynamically at every call so that
     * multi-tenant apps can override it per-tenant without caching issues.
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit-events.pruning.keep_for_days', 365);
        $column = $this->getCreatedAtColumn();

        return static::where($column, '<', now()->subDays($days));
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Recursively compute the diff between two arrays up to a maximum depth.
     *
     * @param  int  $depth  Current recursion depth (starts at 0)
     */
    private function deepDiff(array $old, array $new, int $depth = 0): array
    {
        $maxDepth = (int) config('audit-events.json_diff.max_depth', 3);

        if ($depth >= $maxDepth) {
            return [];
        }

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];

        foreach ($keys as $key) {
            $o = $old[$key] ?? null;
            $n = $new[$key] ?? null;

            if ($o === $n) {
                continue;
            }

            if (is_array($o) && is_array($n)) {
                $subDiff = $this->deepDiff($o, $n, $depth + 1);
                $diff[$key] = ! empty($subDiff)
                    ? ['old' => $o, 'new' => $n, 'diff' => $subDiff]
                    : ['old' => $o, 'new' => $n];
            } else {
                $diff[$key] = ['old' => $o, 'new' => $n];
            }
        }

        return $diff;
    }

    /**
     * Dynamically resolve the User model class for the user() relationship.
     */
    protected function resolveUserModelClass(): string
    {
        $resolver = config('audit-events.user.resolver');
        if (is_callable($resolver)) {
            try {
                $user = call_user_func($resolver);
                if ($user) {
                    return get_class($user);
                }
            } catch (\Throwable) {
                // Ignore and fall back
            }
        }

        $guards = (array) config('audit-events.user.guards', []);
        foreach ($guards as $guard) {
            $providerName = config("auth.guards.$guard.provider");
            if ($providerName) {
                $model = config("auth.providers.$providerName.model");
                if (is_string($model) && class_exists($model)) {
                    return $model;
                }
            }
        }

        if (class_exists('App\\Models\\User')) {
            return 'App\\Models\\User';
        }

        $current = Auth::user();
        if ($current) {
            return get_class($current);
        }

        return Model::class;
    }
}
