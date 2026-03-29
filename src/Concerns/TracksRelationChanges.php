<?php

namespace SoftArtisan\LaravelAuditEvents\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use SoftArtisan\LaravelAuditEvents\AuditContext;

/**
 * TracksRelationChanges — manually record pivot/sync relation changes.
 *
 * Laravel does not emit Eloquent events when pivot tables are modified
 * (e.g. via syncPermissions, sync, attach, detach). Use this trait on
 * models where you want to trace such operations.
 *
 * Example:
 *   $before = $role->permissions->pluck('name')->all();
 *   $role->syncPermissions($permissionIds);
 *   $after = $role->fresh()->permissions->pluck('name')->all();
 *   $role->recordRelationAudit('permissions', $before, $after);
 *
 * Requires the model to also use IsAuditable.
 */
trait TracksRelationChanges
{
    /**
     * Record a relation sync/change as an audit event.
     *
     * @param  string  $relation  Relation name (e.g. "permissions", "roles")
     * @param  array  $before  IDs or labels before the sync
     * @param  array  $after  IDs or labels after the sync
     * @param  array  $context  Arbitrary payload stored in the context column
     */
    public function recordRelationAudit(
        string $relation,
        array $before,
        array $after,
        array $context = []
    ): void {
        $fields = config('audit-events.table_fields');
        $userId = AuditContext::getCauserId() ?? Auth::id();

        $data = [
            $fields['event'] => 'relation.synced',
            $fields['user_id'] => $userId,
            $fields['old_values'] => [$relation => $before],
            $fields['new_values'] => [$relation => $after],
            $fields['context'] => $context,
        ];

        try {
            $data[$fields['url']] = Request::fullUrl();
            $data[$fields['ip_address']] = Request::ip();
            $data[$fields['user_agent']] = Request::userAgent();
        } catch (\Throwable) {
            // Not in an HTTP context (e.g. running in a job or console)
        }

        /** @phpstan-ignore-next-line audits() is provided by IsAuditable */
        $this->audits()->create($data);
    }
}
