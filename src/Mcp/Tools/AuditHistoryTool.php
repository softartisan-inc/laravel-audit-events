<?php

namespace SoftArtisan\LaravelModelAudits\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use SoftArtisan\LaravelModelAudits\Models\ModelAudit;

class AuditHistoryTool extends Tool
{
    protected string $name = 'get_model_audit_history';

    protected string $description = 'Retrieve formatted audit history for a given Eloquent model (by type and id). Returns a list of audits including actor, event, timestamp and a compact diff of changed fields.';

    /**
     * Define input schema: model_type, model_id, limit
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model_class' => $schema->string()->description('Fully-qualified model class name'),
            'model_id' => $schema->string()->description('Identifier (string or integer) of the model instance'),
            'limit' => $schema->integer()->minimum(1)->maximum(200)->default(20)->description('Maximum number of audit entries to return'),
        ];
    }

    /**
     * Handle the tool call and return a Response containing structured JSON text.
     */
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'model_class' => ['required', 'string'],
            'model_id' => ['required'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $modelClass = (string) $data['model_class'];
        $modelId = $data['model_id'];
        $limit = (int) ($data['limit'] ?? 20);

        // Resolve model class from possible morph alias
        if (! class_exists($modelClass)) {
            return Response::error("Unknown model type: {$modelClass}");
        }

        // Build query to fetch audits
        $auditModel = app(ModelAudit::class);
        $fields = config('model-audits.table_fields');
        $morphPrefix = (string) ($fields['morph_prefix'] ?? 'auditable');

        $audits = ModelAudit::query()
            ->where("{$morphPrefix}_type", $modelClass)
            ->where("{$morphPrefix}_id", $modelId)
            ->latest($auditModel->getKeyName())
            ->take($limit)
            ->get();

        $results = $audits->map(function (ModelAudit $audit) use ($fields) {
            $userIdColumn = (string) ($fields['user_id'] ?? 'user_id');
            $eventColumn = (string) ($fields['event'] ?? 'event');
            $createdAtColumn = $audit->getCreatedAtColumn();

            return [
                'audit_id' => $audit->getKey(),
                'event' => $audit->getAttribute($eventColumn),
                'created_at' => $audit->getAttribute($createdAtColumn),
                'user_id' => $audit->getAttribute($userIdColumn),
                'diff' => $audit->getDiff(),
            ];
        })->all();

        return Response::structured([
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'count' => count($results),
            'audits' => $results,
        ])->asAssistant();
    }
}
