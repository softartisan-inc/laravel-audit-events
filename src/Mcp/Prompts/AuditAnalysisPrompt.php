<?php

namespace SoftArtisan\LaravelModelAudits\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class AuditAnalysisPrompt extends Prompt
{
    protected string $name = 'analyze-model-changes';

    protected string $description = 'Analyzes the audit history of a model to detect anomalies or summarize changes.';

    /**
     * Define arguments expected by this prompt (same as the tool, except limit is optional for analysis).
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('model_class', 'Fully-qualified model class name', true),
            new Argument('model_id', 'Identifier of the model instance', true),
        ];
    }

    /**
     * Return a message instructing the AI to use the audit history tool and analyze output.
     */
    public function handle(): ResponseFactory
    {
        $text = "Please retrieve the audit history for the model [model_class] (ID: [model_id]) using the 'get-model-audit-history' tool. Then, summarize the key changes, identify who made them, and flag any potentially suspicious modifications (like sensitive field changes).";

        return Response::make([
            Response::text($text),
        ]);
    }
}
