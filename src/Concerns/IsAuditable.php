<?php

namespace SoftArtisan\LaravelAuditEvents\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use SoftArtisan\LaravelAuditEvents\AuditContext;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;
use SoftArtisan\LaravelAuditEvents\Services\AuditSignatureService;

/**
 * Trait IsAuditable
 *
 * Attach to any Eloquent model to automatically track lifecycle events
 * (created, updated, deleted, restored). Records old/new values, the acting
 * user (resolved via AuditContext then Auth), request metadata and the event
 * name in the configured audit table.
 *
 * Usage in jobs (where Auth::id() is null):
 *   AuditContext::actingAs($userId, ['source' => 'import-job']);
 *   // ... job logic
 *   AuditContext::reset();
 */
trait IsAuditable
{
    /**
     * Register Eloquent model event listeners for auditing.
     *
     * All automatic listeners use recordEloquentAudit() which enforces
     * the configured events whitelist.
     */
    public static function bootIsAuditable(): void
    {
        /** @param  Model&self  $model */
        static::created(function ($model): void {
            if (! config('audit-events.audit_on_create', true)) {
                return;
            }

            $model->recordEloquentAudit('created', [], $model->getAttributes());
        });

        /** @param  Model&self  $model */
        static::updated(function (Model $model): void {
            if (! config('audit-events.audit_on_update', true)) {
                return;
            }

            $changes = $model->getChanges();

            // Ignore when only the updated_at column changed
            if (count($changes) === 1 && array_key_exists($model->getUpdatedAtColumn(), $changes)) {
                return;
            }

            // Use getAttribute() (cast-aware) for both old and new values so that
            // JSON/array cast fields are stored as decoded arrays, not raw JSON strings.
            // getOriginal() already applies casts; getAttribute() does the same for new values.
            $old = [];
            $new = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
                $new[$key] = $model->getAttribute($key);
            }

            /** @phpstan-ignore-next-line */
            $model->recordEloquentAudit('updated', $old, $new);
        });

        /** @param  Model&self  $model */
        static::deleted(function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            /** @phpstan-ignore-next-line SoftDeletes presence is model-specific */
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                // Soft delete — always record
                $model->recordEloquentAudit('deleted', $model->getAttributes(), []);

                return;
            }

            // Hard delete
            if (config('audit-events.remove_on_delete', false)) {
                /** @phpstan-ignore-next-line audits() is provided by this trait */
                $model->audits()->delete();
            } else {
                $model->recordEloquentAudit('deleted', $model->getAttributes(), []);
            }
        });
    }

    /**
     * Polymorphic relation to the audit entries for this model instance.
     *
     * @return MorphMany<ModelAudit, $this>
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(
            ModelAudit::class,
            config('audit-events.table_fields.morph_prefix')
        );
    }

    /**
     * Manually record an audit event for this model (e.g. custom business events).
     *
     * Unlike automatic Eloquent events, free events recorded via saveHistory()
     * are NOT filtered by the events whitelist. A debug warning is logged when
     * the event is not in the whitelist to guide developers to add it if needed.
     *
     * @param  string  $event  Semantic event name
     * @param  array  $oldValues  Previous state
     * @param  array  $newValues  New state
     * @param  array  $context  Arbitrary payload
     */
    public function saveHistory(
        string $event,
        array $oldValues = [],
        array $newValues = [],
        array $context = []
    ): void {
        $this->recordFreeAudit($event, $oldValues, $newValues, $context);
    }

    /**
     * @deprecated Use saveHistory() with the $context parameter instead.
     *
     * Persist a single audit entry. Kept for backward compatibility.
     * Now delegates to recordEloquentAudit() (whitelist enforced).
     */
    protected function recordAudit(
        string $event,
        array $oldValues,
        array $newValues,
        array $context = []
    ): void {
        $this->recordEloquentAudit($event, $oldValues, $newValues, $context);
    }

    /**
     * Return the final list of attributes to hide from audit payloads.
     */
    public function getHiddenForAudit(): array
    {
        $defaultHidden = (array) config('audit-events.global_hidden', []);

        return array_merge($defaultHidden, $this->hidden_for_audit ?? []);
    }

    /**
     * Retrieve the audit history, optionally filtered by event name.
     * Returns the relation to allow chaining (->get(), ->paginate(), etc.).
     */
    public function getAuditHistory(?string $event = null): MorphMany
    {
        $relation = $this->audits();

        if ($event !== null) {
            $relation->where(config('audit-events.table_fields.event'), $event);
        }

        return $relation;
    }

    public function getCreatedHistory(): MorphMany
    {
        return $this->getAuditHistory('created');
    }

    public function getUpdatedHistory(): MorphMany
    {
        return $this->getAuditHistory('updated');
    }

    public function getDeletedHistory(): MorphMany
    {
        return $this->getAuditHistory('deleted');
    }

    public function getRestoredHistory(): MorphMany
    {
        return $this->getAuditHistory('restored');
    }

    // ── Internal helpers ───────────────────────────────────────────────────

    /**
     * Record an automatic Eloquent lifecycle event, subject to the whitelist.
     */
    protected function recordEloquentAudit(
        string $event,
        array $oldValues,
        array $newValues,
        array $context = []
    ): void {
        if (! in_array($event, config('audit-events.events', []), true)) {
            if (config('app.debug')) {
                Log::warning("[audit-events] Event '{$event}' is not in the events whitelist and will not be recorded.");
            }

            return;
        }

        $this->persistAudit($event, $oldValues, $newValues, $context);
    }

    /**
     * Record a free audit event — never blocked by the whitelist.
     * Called by saveHistory() and ModelAudit::record().
     */
    protected function recordFreeAudit(
        string $event,
        array $oldValues,
        array $newValues,
        array $context = []
    ): void {
        if (! in_array($event, config('audit-events.events', []), true) && config('app.debug')) {
            Log::debug("[audit-events] Free event '{$event}' is not in the whitelist. Add it to config('audit-events.events') if you want it listed in stats.");
        }

        $this->persistAudit($event, $oldValues, $newValues, $context);
    }

    /**
     * Write the audit record to the database.
     */
    private function persistAudit(
        string $event,
        array $oldValues,
        array $newValues,
        array $context = []
    ): void {
        $hidden = $this->getHiddenForAudit();
        $oldValues = array_diff_key($oldValues, array_flip($hidden));
        $newValues = array_diff_key($newValues, array_flip($hidden));

        $fields = config('audit-events.table_fields');
        $userId = AuditContext::getCauserId() ?? Auth::id();

        $data = [
            $fields['event'] => $event,
            $fields['user_id'] => $userId,
            $fields['old_values'] => $oldValues,
            $fields['new_values'] => $newValues,
            $fields['context'] => $context ?: null,
        ];

        try {
            $data[$fields['url']] = Request::fullUrl();
            $data[$fields['ip_address']] = Request::ip();
            $data[$fields['user_agent']] = Request::userAgent();
        } catch (\Throwable) {
            // Not in an HTTP context
        }

        if (config('audit-events.integrity.enabled', false)) {
            /** @var AuditSignatureService $signer */
            $signer = app(AuditSignatureService::class);
            $morphName = $fields['morph_prefix'] ?? 'auditable';
            $morphType = $this->getMorphClass();
            $morphId = $this->getKey();
            $tableName = config('audit-events.table_name', 'audit_events');
            $previousHash = $signer->getPreviousHash($morphType, $morphId, $tableName);

            $payload = [
                'auditable_type' => $morphType,
                'auditable_id' => $morphId,
                'event' => $event,
                'user_id' => $userId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'context' => $context ?: null,
                'created_at' => now()->toIso8601String(),
                'previous_hash' => $previousHash,
            ];

            $key = config('audit-events.integrity.key') ?? config('app.key');
            $algorithm = config('audit-events.integrity.algorithm', 'sha256');

            $data['signature'] = $signer->computeSignature($payload, $key, $algorithm);
            $data['previous_hash'] = $previousHash;
        }

        $this->audits()->create($data);
    }
}
