<?php

namespace SoftArtisan\LaravelModelAudits\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class ModelAudit extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [];

    protected $casts = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('model-audits.table_name');

        $fields = config('model-audits.table_fields');
        $this->primaryKey = $fields['id'] ?? 'audit_id';

        $this->fillable = [
            $fields['event'],
            $fields['user_id'],
            $fields['url'],
            $fields['ip_address'],
            $fields['user_agent'],
            $fields['old_values'],
            $fields['new_values'],
        ];

        $this->casts = [
            $fields['old_values'] => 'array',
            $fields['new_values'] => 'array',
        ];
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
     * Uses the configured resolver when provided, otherwise attempts
     * to infer the user model from the Auth configuration.
     */
    public function user(): BelongsTo
    {
        $fields = config('model-audits.table_fields');
        $userModel = $this->resolveUserModelClass();

        return $this->belongsTo($userModel, $fields['user_id']);
    }

    /**
     * Restore the parent (auditable) model to the state described in old_values.
     * Columns that no longer exist in the table are ignored.
     */
    public function restore(): ?Model
    {
        $auditable = $this->auditable;
        if (! $auditable) {
            return null;
        }

        $fields = config('model-audits.table_fields');
        $oldValues = (array) ($this->getAttribute($fields['old_values']) ?? []);

        if (empty($oldValues)) {
            return $auditable; // Nothing to restore
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
     * Return a simplified differences map between old_values and new_values.
     * Example: [ 'name' => ['old' => 'A', 'new' => 'B'] ]
     */
    public function getDiff(): array
    {
        $fields = config('model-audits.table_fields');
        $old = (array) ($this->getAttribute($fields['old_values']) ?? []);
        $new = (array) ($this->getAttribute($fields['new_values']) ?? []);

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $diff = [];
        foreach ($keys as $key) {
            $o = $old[$key] ?? null;
            $n = $new[$key] ?? null;
            if ($o !== $n) {
                $diff[$key] = ['old' => $o, 'new' => $n];
            }
        }

        return $diff;
    }

    /**
     * Automatic pruning configuration (Prunable).
     */
    public function prunable()
    {
        $days = (int) config('model-audits.pruning.keep_for_days', 90);
        $column = $this->getCreatedAtColumn();

        return static::where($column, '<=', now()->subDays($days));
    }

    /**
     * Dynamically resolve the User model class for the user() relationship.
     */
    protected function resolveUserModelClass(): string
    {
        // 1) If a resolver is defined, try to infer the class from it
        $resolver = config('model-audits.user.resolver');
        if (is_callable($resolver)) {
            try {
                $user = call_user_func($resolver);
                if ($user) {
                    return get_class($user);
                }
            } catch (\Throwable $e) {
                // Ignore and fallback
            }
        }

        // 2) Inspect the guards declared in the package configuration
        $guards = (array) config('model-audits.user.guards', []);
        foreach ($guards as $guard) {
            $providerName = config("auth.guards.$guard.provider");
            if ($providerName) {
                $model = config("auth.providers.$providerName.model");
                if (is_string($model) && class_exists($model)) {
                    return $model;
                }
            }
        }

        // 3) Fallback: Laravel's default User model
        if (class_exists('App\\Models\\User')) {
            return 'App\\Models\\User';
        }

        // 4) Last resort: try to get the class from the current authenticated user
        $current = Auth::user();
        if ($current) {
            return get_class($current);
        }

        // Default value, should not be reached in a standard Laravel project
        return Model::class;
    }
}
