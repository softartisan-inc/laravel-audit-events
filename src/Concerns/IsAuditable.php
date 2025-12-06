<?php

namespace SoftArtisan\LaravelModelAudits\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Trait IsAuditable
 *
 * Attach this trait to any Eloquent model to automatically keep a detailed audit trail
 * of lifecycle changes (created, updated, deleted). It can also work alongside
 * SoftDeletes. The trait records old/new values, the acting user, request metadata
 * (URL, IP, user agent) and the event type, using the configuration found in
 * config/model-audits.php.
 */
trait IsAuditable
{
    /**
     * Additional attributes to be excluded from old/new values for this model.
     * These are merged with the global_hidden configuration.
     */
    protected array $hidden_for_audit = [];

    /**
     * Register Eloquent model event listeners for auditing.
     *
     * Respects configuration flags:
     * - audit_on_create (bool)
     * - audit_on_update (bool)
     * - remove_on_delete (bool) — when true on hard delete, all audits are removed
     */
    public static function bootIsAuditable(): void
    {
        /** @param Model&self $model */
        static::created(function (Model $model): void {
            if (! $model instanceof self) {
                return; // PHPStan safety guard
            }
            if (! config('model-audits.audit_on_create', true)) {
                return;
            }
            // On create, there are no old values.
            // Without new_values, this entry simply states the record was created at a given time.
            $model->recordAudit('created', [], $model->getAttributes());
        });

        /** @param Model&self $model */
        static::updated(function (Model $model): void {
            if (! $model instanceof self) {
                return; // PHPStan safety guard
            }
            if (! config('model-audits.audit_on_update', true)) {
                return;
            }
            $changes = $model->getChanges();

            // Ignore when only the updated_at column changed
            if (count($changes) === 1 && array_key_exists($model->getUpdatedAtColumn(), $changes)) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            // Persist only what existed BEFORE the update
            // @phpstan-ignore-next-line Model is guaranteed to be using this trait
            $model->recordAudit('updated', $old, $changes);
        });

        /** @param Model&self $model */
        static::deleted(function (Model $model): void {
            if (! $model instanceof self) {
                return; // PHPStan safety guard
            }
            // Check if the model uses SoftDeletes and if it is being force deleted
            // @phpstan-ignore-next-line SoftDeletes presence is model-specific and known at runtime
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                // It's a soft delete, record it as deleted
                // @phpstan-ignore-next-line Model is guaranteed to be using this trait
                $model->recordAudit('deleted', $model->getAttributes(), []);

                return;
            }

            // It is a hard delete (or model doesn't use SoftDeletes)
            if (config('model-audits.remove_on_delete', false)) {
                // @phpstan-ignore-next-line audits() exists via this trait
                $model->audits()->delete();
            } else {
                // On delete, the old values are everything we had
                // @phpstan-ignore-next-line Model is guaranteed to be using this trait
                $model->recordAudit('deleted', $model->getAttributes(), []);
            }
        });
    }

    /**
     * Polymorphic relation to the audit entries for this model instance.
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(config('model-audits.model_class'), config('model-audits.table_fields.morph_prefix'));
    }

    /**
     * Persist a single audit entry.
     *
     * Uses the configured table field names and hides attributes declared in
     * getHiddenForAudit(). If the event is not declared in config('model-audits.events')
     * it will be ignored (and a warning logged in APP_DEBUG mode).
     */
    protected function recordAudit(string $event, array $oldValues, array $newValues): void
    {
        if (! in_array($event, config('model-audits.events', []))) {
            if (config('app.debug')) {
                Log::warning("Event '$event' is not registered in the model audits configuration.");
            }

            return;
        }

        $hidden = $this->getHiddenForAudit();
        $oldValues = array_diff_key($oldValues, array_flip($hidden));
        $newValues = array_diff_key($newValues, array_flip($hidden));

        $fields = config('model-audits.table_fields');

        $this->audits()->create([
            $fields['event'] => $event,
            $fields['user_id'] => Auth::id(),
            $fields['url'] => Request::fullUrl(),
            $fields['ip_address'] => Request::ip(),
            $fields['user_agent'] => Request::userAgent(),
            $fields['old_values'] => $oldValues,
            $fields['new_values'] => $newValues,
        ]);
    }

    /**
     * Return the final list of attributes to hide from audit payloads
     * by merging the global configuration with model-level overrides.
     */
    public function getHiddenForAudit(): array
    {
        $defaultHidden = config('model-audits.global_hidden');

        return array_merge($defaultHidden, $this->hidden_for_audit ?? []);
    }

    /**
     * Manually record a history.
     * Note: The event must be present in the 'model-audits.events' configuration file to be recorded.
     * unless the validation logic in recordAudit is modified.
     */
    public function saveHistory(string $event, array $oldValues = [], array $newValues = []): void
    {
        $this->recordAudit($event, $oldValues, $newValues);
    }

    /**
     * Retrieve the overall history or filter it by event.
     * Returns the relation to allow chaining (ex: ->get(), ->paginate()).
     */
    public function getAuditHistory(?string $event = null): MorphMany
    {
        $relation = $this->audits();

        if ($event) {
            $relation->where(config('model-audits.table_fields.event'), $event);
        }

        return $relation;
    }

    /**
     * Retrieve the creation history for the associated model.
     *
     * @return MorphMany The audit history data filtered for creation events.
     */
    public function getCreatedHistory(): MorphMany
    {
        return $this->getAuditHistory('created');
    }

    /**
     * Retrieve the updated history for the associated model.
     */
    public function getUpdatedHistory(): MorphMany
    {
        return $this->getAuditHistory('updated');
    }

    /**
     * Retrieve the audit history for deleted records.
     *
     * @return MorphMany Deleted records history.
     */
    public function getDeletedHistory(): MorphMany
    {
        return $this->getAuditHistory('deleted');
    }

    /**
     * Retrieve the restored audit history for the current model.
     *
     * @return MorphMany Returns a MorphMany relationship containing the restored audit records.
     */
    public function getRestoredHistory(): MorphMany
    {
        return $this->getAuditHistory('restored');
    }
}
