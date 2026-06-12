<?php

namespace SoftArtisan\LaravelAuditEvents\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SoftArtisan\LaravelAuditEvents\Models\ModelAudit;

class SearchAuditEventsTool extends Tool
{
    protected string $name = 'search_audit_events';

    protected string $description = 'Search the whole audit trail across every model and free-standing semantic event. Filter by event name (e.g. created, updated, user.logged_in, impersonation.started), acting user, date range, a key/value inside the JSON context payload, and/or a specific anchored model. Returns matching audit entries with actor, event, timestamp, diff and context.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event' => $schema->string()->description('Exact event name to match (e.g. "updated", "impersonation.started"). Omit for any.'),
            'user_id' => $schema->string()->description('Only events performed by this acting user id. Omit for any.'),
            'context_key' => $schema->string()->description('A key inside the JSON context payload to filter on (e.g. "mission_id"). Requires context_value.'),
            'context_value' => $schema->string()->description('The value the context_key must equal.'),
            'auditable_type' => $schema->string()->description('Fully-qualified model class to scope to (e.g. App\\Models\\Mission). Omit for any.'),
            'auditable_id' => $schema->string()->description('Identifier of the anchored model (requires auditable_type).'),
            'from' => $schema->string()->description('ISO-8601 lower bound on created_at (inclusive).'),
            'to' => $schema->string()->description('ISO-8601 upper bound on created_at (inclusive).'),
            'limit' => $schema->integer()->minimum(1)->maximum(200)->default(50)->description('Maximum number of entries to return.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable'],
            'context_key' => ['nullable', 'string', 'max:100'],
            'context_value' => ['nullable'],
            'auditable_type' => ['nullable', 'string', 'max:255'],
            'auditable_id' => ['nullable'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $fields = config('audit-events.table_fields');
        $morph = (string) ($fields['morph_prefix'] ?? 'auditable');
        $limit = (int) ($data['limit'] ?? 50);

        $query = ModelAudit::query();

        if (! empty($data['event'])) {
            $query->whereEvent($data['event']);
        }
        if (isset($data['user_id']) && $data['user_id'] !== '') {
            $query->where($fields['user_id'], $data['user_id']);
        }
        if (! empty($data['context_key']) && array_key_exists('context_value', $data)) {
            $query->whereContext($data['context_key'], $data['context_value']);
        }
        if (! empty($data['auditable_type'])) {
            $query->where("{$morph}_type", $data['auditable_type']);
            if (isset($data['auditable_id']) && $data['auditable_id'] !== '') {
                $query->where("{$morph}_id", $data['auditable_id']);
            }
        }
        if (! empty($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('created_at', '<=', $data['to']);
        }

        $audits = $query->latest('created_at')->take($limit)->get();

        $results = $audits->map(function (ModelAudit $audit) use ($fields, $morph) {
            return [
                'audit_id' => $audit->getKey(),
                'event' => $audit->getAttribute($fields['event']),
                'auditable_type' => $audit->getAttribute("{$morph}_type"),
                'auditable_id' => $audit->getAttribute("{$morph}_id"),
                'user_id' => $audit->getAttribute($fields['user_id']),
                'created_at' => $audit->getAttribute($audit->getCreatedAtColumn()),
                'diff' => $audit->getDiff(),
                'context' => $audit->getAttribute($fields['context']),
            ];
        })->all();

        return Response::json([
            'count' => count($results),
            'filters' => array_filter($data, fn ($v) => $v !== null && $v !== ''),
            'audits' => $results,
        ]);
    }
}
