<?php

namespace SoftArtisan\LaravelAuditEvents\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VerifyAuditIntegrityTool extends Tool
{
    protected string $name = 'verify_audit_integrity';

    protected string $description = 'Verify the cryptographic integrity (HMAC hash chain) of the audit trail and report whether any records were tampered with, deleted or reordered. Optionally scope to a model and/or a date range.';

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()->description('Fully-qualified model class to scope verification to. Omit for the whole trail.'),
            'id' => $schema->string()->description('Identifier of a single model instance (requires model).'),
            'from' => $schema->string()->description('ISO-8601 lower bound on created_at.'),
            'until' => $schema->string()->description('ISO-8601 upper bound on created_at.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('audit-events.integrity.enabled', false)) {
            return Response::error('Cryptographic integrity is disabled (audit-events.integrity.enabled = false). Nothing to verify.');
        }

        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:255'],
            'id' => ['nullable'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
        ]);

        $options = [];
        foreach (['model', 'id', 'from', 'until'] as $opt) {
            if (! empty($data[$opt])) {
                $options["--{$opt}"] = $data[$opt];
            }
        }

        $exitCode = Artisan::call('audit-events:verify', $options);

        return Response::json([
            'intact' => $exitCode === 0,
            'exit_code' => $exitCode,
            'report' => trim(Artisan::output()),
        ]);
    }
}
