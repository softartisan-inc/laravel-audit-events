<?php

namespace SoftArtisan\LaravelModelAudits\Mcp\Servers;

use Laravel\Mcp\Server;
use SoftArtisan\LaravelModelAudits\Mcp\Prompts\AuditAnalysisPrompt;
use SoftArtisan\LaravelModelAudits\Mcp\Tools\AuditHistoryTool;

class ModelAuditsServer extends Server
{
    /** @var array<int, class-string> */
    protected array $tools = [
        AuditHistoryTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $prompts = [
        AuditAnalysisPrompt::class,
    ];
}
